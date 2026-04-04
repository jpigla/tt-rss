<?php
class Dashboards extends Plugin {

	/** @var PluginHost $host */
	private $host;

	function about() {
		return [1.0,
			"Anpassbare Dashboards mit Widgets für Statistiken und Übersichten",
			"tt-ss",
			false];
	}

	function init($host) {
		$this->host = $host;

		$host->add_hook($host::HOOK_TOOLBAR_BUTTON, $this);
		$host->add_hook($host::HOOK_PREFS_TAB, $this);

		$m = new Db_Migrations();
		$m->initialize_for_plugin($this);
		$m->migrate();
	}

	function get_js() {
		return file_get_contents(__DIR__ . "/dashboards.js");
	}

	function get_css() {
		return file_get_contents(__DIR__ . "/dashboards.css");
	}

	/**
	 * Toolbar-Button für Dashboard.
	 */
	function hook_toolbar_button() {
		return "<a href='#' onclick='Plugins.Dashboards.show(); return false'
			class='toolbar-btn' title=\"" . __('Dashboard') . "\">
			<i class='material-icons'>dashboard</i>
			<span class='toolbar-label'>" . __('Dashboard') . "</span></a>";
	}

	/**
	 * Einstellungsseite: Dashboards verwalten.
	 */
	function hook_prefs_tab($args) {
		if ($args != "prefPrefs") return;

		$sth = $this->pdo->prepare("SELECT * FROM ttrss_plugin_dashboards
			WHERE owner_uid = ? ORDER BY created_at DESC");
		$sth->execute([$_SESSION['uid']]);

		?>
		<div dojoType="dijit.layout.AccordionPane"
			title="<i class='material-icons'>dashboard</i> <?= __('Dashboards') ?>">

			<div class="db-prefs-toolbar" style="margin-bottom: 10px">
				<button dojoType="dijit.form.Button"
					onclick="Plugins.Dashboards.createNew()">
					<i class="material-icons">add</i> <?= __('Neues Dashboard erstellen') ?>
				</button>
			</div>

			<table width="100%">
				<tr class="title">
					<td><?= __('Titel') ?></td>
					<td><?= __('Erstellt') ?></td>
					<td></td>
				</tr>
				<?php while ($row = $sth->fetch()) { ?>
				<tr>
					<td><?= htmlspecialchars($row['title']) ?></td>
					<td><?= htmlspecialchars($row['created_at']) ?></td>
					<td>
						<i class="material-icons" style="cursor:pointer"
							onclick="Plugins.Dashboards.show(<?= (int)$row['id'] ?>)"
							title="<?= __('Anzeigen') ?>">visibility</i>
						<i class="material-icons" style="cursor:pointer"
							onclick="Plugins.Dashboards.deleteDashboard(<?= (int)$row['id'] ?>)"
							title="<?= __('Löschen') ?>">delete</i>
					</td>
				</tr>
				<?php } ?>
			</table>
		</div>
		<?php
	}

	/**
	 * Dashboard mit Widgets laden (AJAX).
	 */
	function get_dashboard(): void {
		$id = (int)clean($_REQUEST['id'] ?? 0);

		if ($id) {
			$sth = $this->pdo->prepare("SELECT * FROM ttrss_plugin_dashboards
				WHERE id = ? AND owner_uid = ?");
			$sth->execute([$id, $_SESSION['uid']]);
		} else {
			// Erstes Dashboard laden
			$sth = $this->pdo->prepare("SELECT * FROM ttrss_plugin_dashboards
				WHERE owner_uid = ? ORDER BY id LIMIT 1");
			$sth->execute([$_SESSION['uid']]);
		}

		$dashboard = $sth->fetch();

		if (!$dashboard) {
			// Standard-Dashboard erstellen
			$ins = $this->pdo->prepare("INSERT INTO ttrss_plugin_dashboards
				(owner_uid, title, layout_json) VALUES (?, ?, ?)");
			$default_layout = json_encode([
				['type' => 'unread_stats', 'position' => 0],
				['type' => 'recent_articles', 'position' => 1],
				['type' => 'label_breakdown', 'position' => 2],
				['type' => 'reading_stats', 'position' => 3]
			]);
			$ins->execute([$_SESSION['uid'], 'Standard-Dashboard', $default_layout]);
			$newId = $this->pdo->lastInsertId();

			$sth = $this->pdo->prepare("SELECT * FROM ttrss_plugin_dashboards WHERE id = ?");
			$sth->execute([$newId]);
			$dashboard = $sth->fetch();
		}

		$layout = json_decode($dashboard['layout_json'] ?? '[]', true) ?: [];

		$widgets = [];
		foreach ($layout as $widget_config) {
			$type = $widget_config['type'] ?? '';
			$widgets[] = [
				'type' => $type,
				'title' => $this->get_widget_title($type),
				'data' => $this->get_widget_data($type)
			];
		}

		print json_encode([
			'id' => (int)$dashboard['id'],
			'title' => $dashboard['title'],
			'widgets' => $widgets
		]);
	}

	/**
	 * Einzelnes Widget rendern (AJAX).
	 */
	function render_widget(): void {
		$type = clean($_REQUEST['widget_type'] ?? '');

		print json_encode([
			'type' => $type,
			'title' => $this->get_widget_title($type),
			'data' => $this->get_widget_data($type)
		]);
	}

	/**
	 * @param string $type
	 * @return string
	 */
	private function get_widget_title(string $type): string {
		$titles = [
			'unread_stats' => __('Ungelesene Artikel'),
			'recent_articles' => __('Neueste Artikel'),
			'label_breakdown' => __('Label-Übersicht'),
			'reading_stats' => __('Lese-Statistik'),
		];
		return $titles[$type] ?? $type;
	}

	/**
	 * @param string $type
	 * @return array<string, mixed>
	 */
	private function get_widget_data(string $type): array {
		$uid = $_SESSION['uid'];

		switch ($type) {
			case 'unread_stats':
				$sth = $this->pdo->prepare("SELECT f.title, COUNT(*) AS unread
					FROM ttrss_user_entries ue
					JOIN ttrss_feeds f ON f.id = ue.feed_id
					WHERE ue.owner_uid = ? AND ue.unread = true
					GROUP BY f.title ORDER BY unread DESC LIMIT 20");
				$sth->execute([$uid]);
				$rows = [];
				while ($row = $sth->fetch()) {
					$rows[] = $row;
				}
				return ['feeds' => $rows];

			case 'recent_articles':
				$sth = $this->pdo->prepare("SELECT e.title, e.link,
					SUBSTRING_FOR_DATE(e.updated, 1, 16) AS updated,
					f.title AS feed_title
					FROM ttrss_entries e
					JOIN ttrss_user_entries ue ON ue.ref_id = e.id
					JOIN ttrss_feeds f ON f.id = ue.feed_id
					WHERE ue.owner_uid = ?
					ORDER BY e.updated DESC LIMIT 15");
				$sth->execute([$uid]);
				$rows = [];
				while ($row = $sth->fetch()) {
					$rows[] = $row;
				}
				return ['articles' => $rows];

			case 'label_breakdown':
				$sth = $this->pdo->prepare("SELECT caption, fg_color, bg_color,
					(SELECT COUNT(*) FROM ttrss_user_labels2 ul2
						WHERE ul2.label_id = l.id) AS article_count
					FROM ttrss_labels2 l
					WHERE l.owner_uid = ?
					ORDER BY caption");
				$sth->execute([$uid]);
				$rows = [];
				while ($row = $sth->fetch()) {
					$rows[] = $row;
				}
				return ['labels' => $rows];

			case 'reading_stats':
				// Heute gelesen
				$sth = $this->pdo->prepare("SELECT COUNT(*) AS cnt FROM ttrss_user_entries
					WHERE owner_uid = ? AND unread = false
					AND last_read >= CURRENT_DATE");
				$sth->execute([$uid]);
				$today = (int)($sth->fetch()['cnt'] ?? 0);

				// Diese Woche gelesen
				$sth = $this->pdo->prepare("SELECT COUNT(*) AS cnt FROM ttrss_user_entries
					WHERE owner_uid = ? AND unread = false
					AND last_read >= CURRENT_DATE - INTERVAL '7 days'");
				$sth->execute([$uid]);
				$week = (int)($sth->fetch()['cnt'] ?? 0);

				// Diesen Monat gelesen
				$sth = $this->pdo->prepare("SELECT COUNT(*) AS cnt FROM ttrss_user_entries
					WHERE owner_uid = ? AND unread = false
					AND last_read >= CURRENT_DATE - INTERVAL '30 days'");
				$sth->execute([$uid]);
				$month = (int)($sth->fetch()['cnt'] ?? 0);

				return ['today' => $today, 'week' => $week, 'month' => $month];

			default:
				return [];
		}
	}

	/**
	 * Dashboard-Layout speichern (AJAX).
	 */
	function save_dashboard(): void {
		$id = (int)clean($_REQUEST['id'] ?? 0);
		$title = clean($_REQUEST['title'] ?? '');
		$layout_json = clean($_REQUEST['layout_json'] ?? '[]');

		if (!$id) {
			print json_encode(['error' => 'Fehlende Dashboard-ID']);
			return;
		}

		$sth = $this->pdo->prepare("UPDATE ttrss_plugin_dashboards
			SET title = ?, layout_json = ? WHERE id = ? AND owner_uid = ?");
		$sth->execute([$title, $layout_json, $id, $_SESSION['uid']]);

		print json_encode(['status' => 'ok']);
	}

	/**
	 * Neues Dashboard erstellen (AJAX).
	 */
	function create_dashboard(): void {
		$title = clean($_REQUEST['title'] ?? 'Neues Dashboard');

		$default_layout = json_encode([
			['type' => 'unread_stats', 'position' => 0],
			['type' => 'reading_stats', 'position' => 1]
		]);

		$sth = $this->pdo->prepare("INSERT INTO ttrss_plugin_dashboards
			(owner_uid, title, layout_json) VALUES (?, ?, ?)");
		$sth->execute([$_SESSION['uid'], $title, $default_layout]);

		$id = $this->pdo->lastInsertId();

		print json_encode(['id' => $id, 'status' => 'ok']);
	}

	/**
	 * Dashboard löschen (AJAX).
	 */
	function delete_dashboard(): void {
		$id = (int)clean($_REQUEST['id'] ?? 0);

		$sth = $this->pdo->prepare("DELETE FROM ttrss_plugin_dashboards
			WHERE id = ? AND owner_uid = ?");
		$sth->execute([$id, $_SESSION['uid']]);

		print json_encode(['status' => 'ok']);
	}

	function api_version() {
		return 2;
	}
}
