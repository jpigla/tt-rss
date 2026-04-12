<?php
class Digest_View extends Plugin {

	/** @var PluginHost $host */
	private $host;

	function about() {
		return [1.0,
			"Konfigurierbarer Daily/Weekly Digest aus ausgewählten Feeds und Kategorien",
			"tt-ss",
			false];
	}

	function init($host) {
		$this->host = $host;

		$host->add_hook($host::HOOK_HOUSE_KEEPING, $this);
		$host->add_hook($host::HOOK_MAIN_TOOLBAR_BUTTON, $this);
		$host->add_hook($host::HOOK_PREFS_TAB, $this);

		$m = new Db_Migrations();
		$m->initialize_for_plugin($this);
		$m->migrate();
	}

	function get_js() {
		return file_get_contents(__DIR__ . "/digest_view.js");
	}

	function get_css() {
		return file_get_contents(__DIR__ . "/digest_view.css");
	}

	function get_prefs_js() {
		return file_get_contents(__DIR__ . "/digest_view.js");
	}

	/**
	 * Toolbar-Button für Digest-Ansicht.
	 * @return string
	 */
	function hook_main_toolbar_button() {
		return "<i class='material-icons dv-toolbar-btn'
			onclick=\"Plugins.Digest_View.showLatest()\"
			style='cursor: pointer'
			title=\"" . __('Digest anzeigen') . "\">summarize</i>";
	}

	/**
	 * Housekeeping: Fällige Digests generieren.
	 */
	function hook_house_keeping() {
		$sth = $this->pdo->prepare("SELECT dc.*, u.email, u.login
			FROM ttrss_plugin_digest_configs dc
			JOIN ttrss_users u ON u.id = dc.owner_uid
			WHERE dc.enabled = true");
		$sth->execute();

		while ($config = $sth->fetch()) {
			if ($this->is_digest_due($config)) {
				$this->generate_digest($config);
			}
		}

		// Alte Digest-Issues bereinigen (maximal 100 pro Nutzer behalten)
		$this->pdo->prepare("DELETE FROM ttrss_plugin_digest_issues
			WHERE id IN (
				SELECT id FROM (
					SELECT id, ROW_NUMBER() OVER (PARTITION BY owner_uid ORDER BY generated_at DESC) AS rn
					FROM ttrss_plugin_digest_issues
				) ranked WHERE rn > 100
			)")->execute();
	}

	/**
	 * Prüfen, ob ein Digest fällig ist.
	 * @param array<string, mixed> $config
	 */
	private function is_digest_due(array $config): bool {
		$last = $config['last_generated'];
		$hour = (int)$config['schedule_hour'];
		$current_hour = (int)date('G');

		// Nur innerhalb von 2 Stunden nach geplanter Zeit generieren
		if ($current_hour < $hour || $current_hour > $hour + 2) {
			return false;
		}

		if ($config['frequency'] === 'weekly') {
			$day = (int)$config['schedule_day'];
			if ((int)date('w') !== $day) return false;
		}

		if ($last) {
			$last_ts = strtotime($last);
			$min_interval = $config['frequency'] === 'weekly' ? 6 * 86400 : 20 * 3600;
			if (time() - $last_ts < $min_interval) return false;
		}

		return true;
	}

	/**
	 * Digest generieren und speichern.
	 * @param array<string, mixed> $config
	 */
	private function generate_digest(array $config): void {
		$uid = (int)$config['owner_uid'];
		$config_id = (int)$config['id'];
		$max_articles = min((int)$config['max_articles'], 200);
		$min_score = (int)$config['min_score'];

		// Zeitraum bestimmen
		$interval = $config['frequency'] === 'weekly' ? '7 days' : '1 day';
		$since = $config['last_generated'] ?? date('Y-m-d H:i:s', strtotime("-$interval"));

		// Feed- und Kategorie-Filter
		$feed_ids = array_filter(array_map('intval', explode(',', $config['feed_ids'] ?? '')));
		$cat_ids = array_filter(array_map('intval', explode(',', $config['cat_ids'] ?? '')));

		// Query zusammenbauen
		$where = ["ue.owner_uid = ?", "e.date_entered > ?"];
		$params = [$uid, $since];

		if ($min_score > 0) {
			$where[] = "ue.score >= ?";
			$params[] = $min_score;
		}

		$feed_filter = [];
		if (!empty($feed_ids)) {
			$placeholders = implode(',', array_fill(0, count($feed_ids), '?'));
			$feed_filter[] = "ue.feed_id IN ($placeholders)";
			$params = array_merge($params, $feed_ids);
		}
		if (!empty($cat_ids)) {
			$placeholders = implode(',', array_fill(0, count($cat_ids), '?'));
			$feed_filter[] = "f.cat_id IN ($placeholders)";
			$params = array_merge($params, $cat_ids);
		}

		if (!empty($feed_filter)) {
			$where[] = '(' . implode(' OR ', $feed_filter) . ')';
		}

		$where_sql = implode(' AND ', $where);

		$sth = $this->pdo->prepare("SELECT e.id, e.title, e.link, e.content,
				e.date_entered, e.author,
				f.title AS feed_title, f.id AS feed_id,
				fc.title AS cat_title,
				ue.score
			FROM ttrss_entries e
			JOIN ttrss_user_entries ue ON ue.ref_id = e.id
			LEFT JOIN ttrss_feeds f ON f.id = ue.feed_id
			LEFT JOIN ttrss_feed_categories fc ON fc.id = f.cat_id
			WHERE $where_sql
			ORDER BY e.date_entered DESC
			LIMIT ?");
		$params[] = $max_articles;
		$sth->execute($params);

		$articles = [];
		while ($row = $sth->fetch()) {
			$articles[] = $row;
		}

		if (empty($articles)) return;

		// HTML-Digest generieren
		$html = $this->render_digest_html($config, $articles);

		$freq_label = $config['frequency'] === 'weekly' ? 'Wochen' : 'Tages';
		$title = $config['title'] . ' — ' . $freq_label . '-Digest vom ' . date('d.m.Y');

		// Issue speichern
		$sth = $this->pdo->prepare("INSERT INTO ttrss_plugin_digest_issues
			(config_id, owner_uid, title, article_count, html_content, generated_at)
			VALUES (?, ?, ?, ?, ?, NOW())");
		$sth->execute([$config_id, $uid, $title, count($articles), $html]);

		// Letzte Generierung aktualisieren
		$sth = $this->pdo->prepare("UPDATE ttrss_plugin_digest_configs
			SET last_generated = NOW() WHERE id = ?");
		$sth->execute([$config_id]);

		// Optional per E-Mail senden
		if (!empty($config['send_email']) && !empty($config['email'])) {
			$this->send_email($config, $title, $html);
		}
	}

	/**
	 * Digest als HTML rendern.
	 * @param array<string, mixed> $config
	 * @param array<array<string, mixed>> $articles
	 */
	private function render_digest_html(array $config, array $articles): string {
		// Nach Kategorie/Feed gruppieren
		$grouped = [];
		foreach ($articles as $art) {
			$group = $art['cat_title'] ?? ($art['feed_title'] ?? 'Ohne Kategorie');
			$grouped[$group][] = $art;
		}

		$html = '<div class="dv-digest">';

		foreach ($grouped as $group_name => $group_articles) {
			$html .= '<div class="dv-group">';
			$html .= '<h2 class="dv-group-title">' . htmlspecialchars($group_name) . '</h2>';

			foreach ($group_articles as $art) {
				$excerpt = strip_tags($art['content'] ?? '');
				$excerpt = mb_substr($excerpt, 0, 250);
				if (mb_strlen(strip_tags($art['content'] ?? '')) > 250) {
					$excerpt .= '…';
				}

				$html .= '<div class="dv-article">';
				$html .= '<h3 class="dv-article-title">';
				$html .= '<a href="' . htmlspecialchars($art['link']) . '" target="_blank">';
				$html .= htmlspecialchars($art['title']);
				$html .= '</a></h3>';

				$meta = [];
				if (!empty($art['feed_title'])) {
					$meta[] = htmlspecialchars($art['feed_title']);
				}
				if (!empty($art['author'])) {
					$meta[] = htmlspecialchars($art['author']);
				}
				$meta[] = date('d.m. H:i', strtotime($art['date_entered']));

				$html .= '<div class="dv-meta">' . implode(' · ', $meta) . '</div>';
				$html .= '<p class="dv-excerpt">' . htmlspecialchars($excerpt) . '</p>';
				$html .= '</div>';
			}

			$html .= '</div>';
		}

		$html .= '</div>';
		return $html;
	}

	/**
	 * Digest per E-Mail senden.
	 * @param array<string, mixed> $config
	 */
	private function send_email(array $config, string $subject, string $html): void {
		if (!class_exists('Mailer')) return;

		$mailer = new Mailer();
		$mailer->mail([
			"to_name" => $config['login'] ?? '',
			"to_address" => $config['email'],
			"subject" => '[tt-ss] ' . $subject,
			"message" => strip_tags($html),
			"message_html" => $html,
		]);
	}

	/**
	 * Letzten Digest anzeigen.
	 */
	function view_latest(): void {
		$sth = $this->pdo->prepare("SELECT title, html_content, article_count, generated_at
			FROM ttrss_plugin_digest_issues
			WHERE owner_uid = ?
			ORDER BY generated_at DESC LIMIT 1");
		$sth->execute([$_SESSION['uid']]);
		$issue = $sth->fetch();

		if (!$issue) {
			print json_encode(["error" => __("Noch kein Digest generiert.")]);
			return;
		}

		print json_encode([
			"title" => $issue['title'],
			"html" => $issue['html_content'],
			"count" => (int)$issue['article_count'],
			"date" => $issue['generated_at'],
		]);
	}

	/**
	 * Archiv vergangener Digests.
	 */
	function view_archive(): void {
		$offset = (int)clean($_REQUEST['offset'] ?? 0);

		$sth = $this->pdo->prepare("SELECT id, title, article_count, generated_at
			FROM ttrss_plugin_digest_issues
			WHERE owner_uid = ?
			ORDER BY generated_at DESC
			LIMIT 20 OFFSET ?");
		$sth->execute([$_SESSION['uid'], $offset]);

		$issues = [];
		while ($row = $sth->fetch()) {
			$issues[] = [
				'id' => (int)$row['id'],
				'title' => $row['title'],
				'count' => (int)$row['article_count'],
				'date' => $row['generated_at'],
			];
		}

		print json_encode($issues);
	}

	/**
	 * Einzelnen Digest-Issue laden.
	 */
	function view_issue(): void {
		$issue_id = (int)clean($_REQUEST['issue_id'] ?? 0);

		$sth = $this->pdo->prepare("SELECT title, html_content, article_count, generated_at
			FROM ttrss_plugin_digest_issues
			WHERE id = ? AND owner_uid = ?");
		$sth->execute([$issue_id, $_SESSION['uid']]);
		$issue = $sth->fetch();

		if (!$issue) {
			print json_encode(["error" => __("Digest nicht gefunden.")]);
			return;
		}

		print json_encode([
			"title" => $issue['title'],
			"html" => $issue['html_content'],
			"count" => (int)$issue['article_count'],
			"date" => $issue['generated_at'],
		]);
	}

	/**
	 * Digest jetzt manuell generieren.
	 */
	function generate_now(): void {
		$config_id = (int)clean($_REQUEST['config_id'] ?? 0);

		$sth = $this->pdo->prepare("SELECT dc.*, u.email, u.login
			FROM ttrss_plugin_digest_configs dc
			JOIN ttrss_users u ON u.id = dc.owner_uid
			WHERE dc.id = ? AND dc.owner_uid = ?");
		$sth->execute([$config_id, $_SESSION['uid']]);
		$config = $sth->fetch();

		if (!$config) {
			print json_encode(["error" => __("Konfiguration nicht gefunden.")]);
			return;
		}

		$this->generate_digest($config);
		print json_encode(["message" => __("Digest wurde generiert.")]);
	}

	/**
	 * Digest-Konfigurationen laden.
	 */
	function get_configs(): void {
		$sth = $this->pdo->prepare("SELECT id, title, frequency, schedule_day,
				schedule_hour, feed_ids, cat_ids, min_score, max_articles,
				send_email, enabled, last_generated
			FROM ttrss_plugin_digest_configs
			WHERE owner_uid = ?
			ORDER BY title");
		$sth->execute([$_SESSION['uid']]);

		$configs = [];
		while ($row = $sth->fetch()) {
			$configs[] = $row;
		}

		print json_encode($configs);
	}

	/**
	 * Digest-Konfiguration speichern/erstellen.
	 */
	function save_config(): void {
		$id = (int)clean($_POST['config_id'] ?? 0);
		$title = clean($_POST['dv_title'] ?? 'Mein Digest');
		$frequency = clean($_POST['dv_frequency'] ?? 'daily');
		$schedule_day = (int)clean($_POST['dv_schedule_day'] ?? 1);
		$schedule_hour = (int)clean($_POST['dv_schedule_hour'] ?? 8);
		$feed_ids = clean($_POST['dv_feed_ids'] ?? '');
		$cat_ids = clean($_POST['dv_cat_ids'] ?? '');
		$min_score = (int)clean($_POST['dv_min_score'] ?? 0);
		$max_articles = max(1, min(200, (int)clean($_POST['dv_max_articles'] ?? 50)));
		$send_email = checkbox_to_sql_bool($_POST['dv_send_email'] ?? '');
		$enabled = checkbox_to_sql_bool($_POST['dv_enabled'] ?? '');

		if (!in_array($frequency, ['daily', 'weekly'])) $frequency = 'daily';
		$schedule_hour = max(0, min(23, $schedule_hour));
		$schedule_day = max(0, min(6, $schedule_day));

		// Feed-IDs validieren (nur Zahlen und Kommas)
		$feed_ids = implode(',', array_filter(array_map('intval', explode(',', $feed_ids))));
		$cat_ids = implode(',', array_filter(array_map('intval', explode(',', $cat_ids))));

		if ($id > 0) {
			$sth = $this->pdo->prepare("UPDATE ttrss_plugin_digest_configs
				SET title = ?, frequency = ?, schedule_day = ?, schedule_hour = ?,
					feed_ids = ?, cat_ids = ?, min_score = ?, max_articles = ?,
					send_email = ?, enabled = ?
				WHERE id = ? AND owner_uid = ?");
			$sth->execute([$title, $frequency, $schedule_day, $schedule_hour,
				$feed_ids, $cat_ids, $min_score, $max_articles,
				$send_email, $enabled, $id, $_SESSION['uid']]);
		} else {
			$sth = $this->pdo->prepare("INSERT INTO ttrss_plugin_digest_configs
				(owner_uid, title, frequency, schedule_day, schedule_hour,
				 feed_ids, cat_ids, min_score, max_articles, send_email, enabled)
				VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
			$sth->execute([$_SESSION['uid'], $title, $frequency, $schedule_day, $schedule_hour,
				$feed_ids, $cat_ids, $min_score, $max_articles, $send_email, $enabled]);
		}

		echo __("Digest-Konfiguration gespeichert.");
	}

	/**
	 * Digest-Konfiguration löschen.
	 */
	function delete_config(): void {
		$id = (int)clean($_REQUEST['config_id'] ?? 0);

		$sth = $this->pdo->prepare("DELETE FROM ttrss_plugin_digest_configs
			WHERE id = ? AND owner_uid = ?");
		$sth->execute([$id, $_SESSION['uid']]);

		print json_encode(["message" => __("Digest gelöscht.")]);
	}

	function hook_prefs_tab($args) {
		if ($args != "prefPrefs") return;

		$configs = [];
		$sth = $this->pdo->prepare("SELECT * FROM ttrss_plugin_digest_configs
			WHERE owner_uid = ? ORDER BY title");
		$sth->execute([$_SESSION['uid']]);
		while ($row = $sth->fetch()) {
			$configs[] = $row;
		}

		// Feeds und Kategorien für Auswahl laden
		$feeds = [];
		$sth = $this->pdo->prepare("SELECT id, title FROM ttrss_feeds
			WHERE owner_uid = ? ORDER BY title");
		$sth->execute([$_SESSION['uid']]);
		while ($row = $sth->fetch()) {
			$feeds[] = $row;
		}

		$cats = [];
		$sth = $this->pdo->prepare("SELECT id, title FROM ttrss_feed_categories
			WHERE owner_uid = ? ORDER BY title");
		$sth->execute([$_SESSION['uid']]);
		while ($row = $sth->fetch()) {
			$cats[] = $row;
		}

		?>
		<div dojoType="dijit.layout.AccordionPane"
			title="<i class='material-icons'>summarize</i> <?= __('Digest-Konfiguration') ?>">

			<?php if (!empty($configs)): ?>
				<h3><?= __('Vorhandene Digests') ?></h3>
				<table class="fh-table" style="margin-bottom: 16px;">
					<thead><tr>
						<th><?= __('Name') ?></th>
						<th><?= __('Häufigkeit') ?></th>
						<th><?= __('Uhrzeit') ?></th>
						<th><?= __('Aktiv') ?></th>
						<th><?= __('Letzte Ausgabe') ?></th>
						<th></th>
					</tr></thead>
					<tbody>
					<?php foreach ($configs as $c): ?>
						<tr>
							<td><?= htmlspecialchars($c['title']) ?></td>
							<td><?= $c['frequency'] === 'weekly' ? __('Wöchentlich') : __('Täglich') ?></td>
							<td><?= sprintf('%02d:00', (int)$c['schedule_hour']) ?></td>
							<td><?= $c['enabled'] ? '✓' : '—' ?></td>
							<td><?= $c['last_generated'] ? date('d.m.Y H:i', strtotime($c['last_generated'])) : '—' ?></td>
							<td>
								<button dojoType="dijit.form.Button"
									onclick="Plugins.Digest_View.generateNow(<?= (int)$c['id'] ?>)">
									<?= __('Jetzt generieren') ?>
								</button>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h3><?= __('Neuen Digest erstellen / bearbeiten') ?></h3>
			<form dojoType="dijit.form.Form">
				<?= \Controls\pluginhandler_tags($this, "save_config") ?>

				<script type="dojo/method" event="onSubmit" args="evt">
					evt.preventDefault();
					if (this.validate()) {
						Notify.progress('Wird gespeichert...', true);
						xhr.post("backend.php", this.getValues(), (reply) => {
							Notify.info(reply);
						})
					}
				</script>

				<input type="hidden" name="config_id" value="0">

				<fieldset>
					<label>
						<input dojoType="dijit.form.CheckBox" name="dv_enabled" type="checkbox" checked>
						<?= __('Aktiviert') ?>
					</label>
				</fieldset>
				<fieldset>
					<label><?= __('Name:') ?></label>
					<input dojoType="dijit.form.TextBox" name="dv_title"
						style="width: 300px" value="Mein Digest">
				</fieldset>
				<fieldset>
					<label><?= __('Häufigkeit:') ?></label>
					<select dojoType="dijit.form.Select" name="dv_frequency" style="width: 150px">
						<option value="daily"><?= __('Täglich') ?></option>
						<option value="weekly"><?= __('Wöchentlich') ?></option>
					</select>
				</fieldset>
				<fieldset>
					<label><?= __('Uhrzeit:') ?></label>
					<input dojoType="dijit.form.NumberSpinner" name="dv_schedule_hour"
						value="8" constraints="{min: 0, max: 23}" style="width: 80px"> Uhr
				</fieldset>
				<fieldset>
					<label><?= __('Wochentag (nur wöchentlich):') ?></label>
					<select dojoType="dijit.form.Select" name="dv_schedule_day" style="width: 150px">
						<option value="1"><?= __('Montag') ?></option>
						<option value="2"><?= __('Dienstag') ?></option>
						<option value="3"><?= __('Mittwoch') ?></option>
						<option value="4"><?= __('Donnerstag') ?></option>
						<option value="5"><?= __('Freitag') ?></option>
						<option value="6"><?= __('Samstag') ?></option>
						<option value="0"><?= __('Sonntag') ?></option>
					</select>
				</fieldset>
				<fieldset>
					<label><?= __('Kategorien (IDs, kommasepariert, leer = alle):') ?></label>
					<input dojoType="dijit.form.TextBox" name="dv_cat_ids"
						style="width: 300px" placeholder="z.B. 1,3,5">
					<small><?= __('Verfügbar: ') ?>
						<?php foreach ($cats as $cat): ?>
							<?= (int)$cat['id'] ?>=<?= htmlspecialchars($cat['title']) ?>,
						<?php endforeach; ?>
					</small>
				</fieldset>
				<fieldset>
					<label><?= __('Feed-IDs (kommasepariert, leer = alle):') ?></label>
					<input dojoType="dijit.form.TextBox" name="dv_feed_ids"
						style="width: 300px" placeholder="z.B. 10,25,42">
				</fieldset>
				<fieldset>
					<label><?= __('Mindest-Score:') ?></label>
					<input dojoType="dijit.form.NumberSpinner" name="dv_min_score"
						value="0" constraints="{min: -100, max: 100}" style="width: 80px">
				</fieldset>
				<fieldset>
					<label><?= __('Max. Artikel:') ?></label>
					<input dojoType="dijit.form.NumberSpinner" name="dv_max_articles"
						value="50" constraints="{min: 1, max: 200}" style="width: 80px">
				</fieldset>
				<fieldset>
					<label>
						<input dojoType="dijit.form.CheckBox" name="dv_send_email" type="checkbox">
						<?= __('Auch per E-Mail senden') ?>
					</label>
				</fieldset>

				<hr/>
				<?= \Controls\submit_tag(__("Speichern")) ?>
			</form>
		</div>
		<?php
	}

	function api_version() {
		return 2;
	}
}
