<?php
class Ai_Reports extends Plugin {

	/** @var PluginHost $host */
	private $host;

	function about() {
		return [1.0,
			"KI-gestützte Berichte aus mehreren Artikeln (benötigt ai_core)",
			"tt-ss",
			false];
	}

	function init($host) {
		$this->host = $host;

		$host->add_hook($host::HOOK_PREFS_TAB, $this);
		$host->add_hook($host::HOOK_TOOLBAR_BUTTON, $this);

		$this->init_schema();
	}

	private function init_schema(): void {
		try {
			$this->pdo->query("SELECT 1 FROM ttrss_plugin_ai_reports LIMIT 1");
		} catch (\PDOException $e) {
			$schema_file = __DIR__ . "/sql/pgsql/schema.sql";
			if (file_exists($schema_file)) {
				$schema = file_get_contents($schema_file);
				foreach (explode(';', $schema) as $stmt) {
					$stmt = trim($stmt);
					if (!empty($stmt)) {
						$this->pdo->query($stmt);
					}
				}
			}
		}
	}

	function get_js() {
		return file_get_contents(__DIR__ . "/ai_reports.js");
	}

	function get_css() {
		return file_get_contents(__DIR__ . "/ai_reports.css");
	}

	function hook_toolbar_button() {
		return "<i class='material-icons ai-reports-toolbar-btn'
			onclick=\"Plugins.Ai_Reports.openDialog()\"
			style='cursor: pointer'
			title=\"" . __('Report erstellen') . "\">assessment</i>";
	}

	/**
	 * Generiert einen Report aus ausgewählten Artikeln.
	 */
	function generate(): void {
		$owner_uid = $_SESSION['uid'];

		if (!PluginHost::getInstance()->get_plugin('ai_core')) {
			print json_encode(["error" => "Plugin ai_core ist nicht aktiviert"]);
			return;
		}

		$article_ids_raw = clean($_REQUEST['article_ids'] ?? '');
		$feed_id = (int) clean($_REQUEST['feed_id'] ?? 0);
		$days = (int) clean($_REQUEST['days'] ?? 7);

		$article_ids = [];

		if (!empty($article_ids_raw)) {
			$article_ids = array_map('intval', array_filter(explode(',', $article_ids_raw)));
		} elseif ($feed_id > 0) {
			$sth = $this->pdo->prepare("SELECT ue.ref_id
				FROM ttrss_user_entries ue
				JOIN ttrss_entries e ON e.id = ue.ref_id
				WHERE ue.owner_uid = ? AND ue.feed_id = ?
				AND e.date_entered > NOW() - CAST((? || ' days') AS INTERVAL)
				ORDER BY e.date_entered DESC
				LIMIT 50");
			$sth->execute([$owner_uid, $feed_id, $days]);

			while ($row = $sth->fetch()) {
				$article_ids[] = (int) $row['ref_id'];
			}
		}

		if (empty($article_ids)) {
			print json_encode(["error" => "Keine Artikel ausgewählt"]);
			return;
		}

		// Artikelinhalte laden
		$placeholders = implode(',', array_fill(0, count($article_ids), '?'));
		$params = array_merge($article_ids, [$owner_uid]);

		$sth = $this->pdo->prepare("SELECT e.id, e.title, e.content, e.date_entered
			FROM ttrss_entries e
			JOIN ttrss_user_entries ue ON ue.ref_id = e.id
			WHERE e.id IN ($placeholders) AND ue.owner_uid = ?
			ORDER BY e.date_entered DESC");
		$sth->execute($params);

		$articles_text = '';
		$article_count = 0;
		while ($row = $sth->fetch()) {
			$content = strip_tags($row['content']);
			if (mb_strlen($content) > 1500) {
				$content = mb_substr($content, 0, 1500) . '...';
			}
			$articles_text .= "---\nTitel: " . $row['title'] . "\nDatum: " . $row['date_entered'] . "\n" . $content . "\n\n";
			$article_count++;
		}

		if (empty($articles_text)) {
			print json_encode(["error" => "Keine Artikelinhalte gefunden"]);
			return;
		}

		$config = Ai_Core::get_config();
		$result = Ai_Core::complete(
			"Erstelle einen strukturierten Bericht aus den folgenden Artikeln. Fasse Hauptthemen zusammen, identifiziere Trends und Muster. Antworte auf Deutsch.",
			$articles_text,
			2048
		);

		if ($result === false) {
			print json_encode(["error" => "KI-Anfrage fehlgeschlagen. Bitte Konfiguration prüfen."]);
			return;
		}

		$report_title = "Bericht vom " . date('d.m.Y H:i') . " ($article_count Artikel)";

		$sth = $this->pdo->prepare("INSERT INTO ttrss_plugin_ai_reports
			(owner_uid, title, report_text, source_articles, model_used)
			VALUES (?, ?, ?, ?, ?)");
		$sth->execute([
			$owner_uid,
			$report_title,
			$result,
			json_encode($article_ids),
			$config['model']
		]);

		$report_id = $this->pdo->lastInsertId();

		print json_encode([
			"id" => $report_id,
			"title" => $report_title,
			"report_text" => $result,
			"model" => $config['model'],
			"article_count" => $article_count
		]);
	}

	/**
	 * Gibt die Berichte des Benutzers zurück.
	 */
	function get_reports(): void {
		$owner_uid = $_SESSION['uid'];

		$sth = $this->pdo->prepare("SELECT id, title, model_used, source_articles, created_at
			FROM ttrss_plugin_ai_reports
			WHERE owner_uid = ?
			ORDER BY created_at DESC
			LIMIT 50");
		$sth->execute([$owner_uid]);

		$reports = [];
		while ($row = $sth->fetch()) {
			$source_ids = json_decode($row['source_articles'], true) ?: [];
			$reports[] = [
				'id' => (int)$row['id'],
				'title' => $row['title'],
				'model' => $row['model_used'],
				'article_count' => count($source_ids),
				'created_at' => $row['created_at']
			];
		}

		print json_encode($reports);
	}

	/**
	 * Gibt einen einzelnen Bericht zurück.
	 */
	function get_report(): void {
		$id = (int) clean($_REQUEST['id'] ?? 0);
		$owner_uid = $_SESSION['uid'];

		$sth = $this->pdo->prepare("SELECT id, title, report_text, model_used, source_articles, created_at
			FROM ttrss_plugin_ai_reports
			WHERE id = ? AND owner_uid = ?");
		$sth->execute([$id, $owner_uid]);

		$row = $sth->fetch();
		if (!$row) {
			print json_encode(["error" => "Bericht nicht gefunden"]);
			return;
		}

		print json_encode([
			'id' => (int)$row['id'],
			'title' => $row['title'],
			'report_text' => $row['report_text'],
			'model' => $row['model_used'],
			'source_articles' => json_decode($row['source_articles'], true) ?: [],
			'created_at' => $row['created_at']
		]);
	}

	/**
	 * Löscht einen Bericht.
	 */
	function delete_report(): void {
		$id = (int) clean($_REQUEST['id'] ?? 0);
		$owner_uid = $_SESSION['uid'];

		$sth = $this->pdo->prepare("DELETE FROM ttrss_plugin_ai_reports
			WHERE id = ? AND owner_uid = ?");
		$sth->execute([$id, $owner_uid]);

		print json_encode(["ok" => true]);
	}

	/**
	 * Gibt die Feeds des Benutzers zurück (für Feed-Auswahl im Dialog).
	 */
	function get_feeds(): void {
		$owner_uid = $_SESSION['uid'];

		$sth = $this->pdo->prepare("SELECT id, title FROM ttrss_feeds
			WHERE owner_uid = ?
			ORDER BY title");
		$sth->execute([$owner_uid]);

		$feeds = [];
		while ($row = $sth->fetch()) {
			$feeds[] = ['id' => (int)$row['id'], 'title' => $row['title']];
		}

		print json_encode($feeds);
	}

	function hook_prefs_tab($args) {
		if ($args != "prefPrefs") return;

		$owner_uid = $_SESSION['uid'];

		$sth = $this->pdo->prepare("SELECT id, title, model_used, source_articles, created_at
			FROM ttrss_plugin_ai_reports
			WHERE owner_uid = ?
			ORDER BY created_at DESC
			LIMIT 20");
		$sth->execute([$owner_uid]);

		$reports = [];
		while ($row = $sth->fetch()) {
			$reports[] = $row;
		}

		?>
		<div dojoType="dijit.layout.AccordionPane"
			title="<i class='material-icons'>assessment</i> <?= __('KI-Berichte') ?>">

			<?= format_notice(__("Berichte aus mehreren Artikeln generieren. Klicke auf das Bericht-Symbol in der Toolbar.")) ?>

			<?php if (empty($reports)) { ?>
				<p class="text-muted"><?= __('Noch keine Berichte erstellt.') ?></p>
			<?php } else { ?>
				<table width="100%" class="prefReportsTable">
					<thead>
						<tr>
							<th><?= __('Titel') ?></th>
							<th><?= __('Modell') ?></th>
							<th><?= __('Artikel') ?></th>
							<th><?= __('Erstellt') ?></th>
							<th width="80"><?= __('Aktionen') ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($reports as $r) {
							$source_ids = json_decode($r['source_articles'], true) ?: [];
							?>
							<tr>
								<td>
									<a href="#" onclick="Plugins.Ai_Reports.viewReport(<?= (int)$r['id'] ?>); return false;">
										<?= htmlspecialchars($r['title']) ?>
									</a>
								</td>
								<td><?= htmlspecialchars($r['model_used']) ?></td>
								<td><?= count($source_ids) ?></td>
								<td><?= htmlspecialchars($r['created_at']) ?></td>
								<td>
									<i class="material-icons" style="cursor:pointer"
										onclick="Plugins.Ai_Reports.deleteReport(<?= (int)$r['id'] ?>)"
										title="<?= __('Löschen') ?>">delete</i>
								</td>
							</tr>
						<?php } ?>
					</tbody>
				</table>
			<?php } ?>
		</div>
		<?php
	}

	function api_version() {
		return 2;
	}
}
