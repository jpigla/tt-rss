<?php
class Feed_Health extends Plugin {

	/** @var PluginHost $host */
	private $host;

	function about() {
		return [1.0,
			"Feed-Gesundheitsüberwachung: Fehler-Tracking, Staleness-Erkennung, Qualitätsmetriken",
			"tt-ss",
			false];
	}

	function init($host) {
		$this->host = $host;

		$host->add_hook($host::HOOK_FEED_FETCHED, $this);
		$host->add_hook($host::HOOK_HOUSE_KEEPING, $this);
		$host->add_hook($host::HOOK_MAIN_TOOLBAR_BUTTON, $this);
		$host->add_hook($host::HOOK_PREFS_TAB, $this);
		$host->add_hook($host::HOOK_PREFS_EDIT_FEED, $this);

		$m = new Db_Migrations();
		$m->initialize_for_plugin($this);
		$m->migrate();
	}

	function get_js() {
		return file_get_contents(__DIR__ . "/feed_health.js");
	}

	function get_css() {
		return file_get_contents(__DIR__ . "/feed_health.css");
	}

	function get_prefs_js() {
		return file_get_contents(__DIR__ . "/feed_health.js");
	}

	/**
	 * Feed-Fetch-Ergebnis aufzeichnen.
	 * @param string $feed_data Raw-Feed-Daten
	 * @param string $fetch_url Abruf-URL
	 * @param int $owner_uid Benutzer-ID
	 * @param array<string, mixed>|int $feed Feed-Daten oder Feed-ID
	 * @return string Unveränderte Feed-Daten
	 */
	function hook_feed_fetched($feed_data, $fetch_url = '', $owner_uid = 0, $feed = 0) {
		if (!$owner_uid) return $feed_data;

		$feed_id = is_array($feed) ? (int)($feed['id'] ?? 0) : (int)$feed;
		if ($feed_id <= 0) return $feed_data;

		// Status anhand der Feed-Tabelle bestimmen
		$sth = $this->pdo->prepare("SELECT last_error, last_successful_update,
				last_updated, update_interval
			FROM ttrss_feeds WHERE id = ? AND owner_uid = ?");
		$sth->execute([$feed_id, $owner_uid]);
		$feed_info = $sth->fetch();

		if (!$feed_info) return $feed_data;

		$status = 'ok';
		$error_msg = '';

		if (!empty($feed_info['last_error'])) {
			$status = 'error';
			$error_msg = $feed_info['last_error'];
		} elseif (empty($feed_data)) {
			$status = 'error';
			$error_msg = 'Leere Antwort vom Server';
		}

		// Eingeschränkt: max. 1 Eintrag pro Feed und Stunde
		$sth = $this->pdo->prepare("SELECT id FROM ttrss_plugin_feed_health
			WHERE feed_id = ? AND owner_uid = ?
			AND check_time > NOW() - INTERVAL '1 hour'
			LIMIT 1");
		$sth->execute([$feed_id, $owner_uid]);

		if (!$sth->fetch()) {
			$sth = $this->pdo->prepare("INSERT INTO ttrss_plugin_feed_health
				(feed_id, owner_uid, status, error_message, check_time)
				VALUES (?, ?, ?, ?, NOW())");
			$sth->execute([$feed_id, $owner_uid, $status, $error_msg]);
		}

		return $feed_data;
	}

	/**
	 * Toolbar-Button für Health-Dashboard.
	 * @return string
	 */
	function hook_main_toolbar_button() {
		return "<i class='material-icons fh-dashboard-btn'
			onclick=\"Plugins.Feed_Health.showDashboard()\"
			style='cursor: pointer'
			title=\"" . __('Feed-Gesundheit') . "\">monitor_heart</i>";
	}

	/**
	 * Dashboard-Daten abrufen.
	 */
	function get_dashboard(): void {
		$uid = $_SESSION['uid'];
		$staleness_days = (int)($this->host->get($this, "staleness_days", 14));

		$data = [
			'summary' => ['healthy' => 0, 'stale' => 0, 'error' => 0, 'total' => 0],
			'problem_feeds' => [],
			'recent_errors' => [],
		];

		// Gesamtzahl Feeds
		$sth = $this->pdo->prepare("SELECT COUNT(*) AS cnt FROM ttrss_feeds WHERE owner_uid = ?");
		$sth->execute([$uid]);
		$data['summary']['total'] = (int)$sth->fetch()['cnt'];

		// Feeds mit Fehlern (last_error nicht leer)
		$sth = $this->pdo->prepare("SELECT COUNT(*) AS cnt FROM ttrss_feeds
			WHERE owner_uid = ? AND last_error != ''");
		$sth->execute([$uid]);
		$data['summary']['error'] = (int)$sth->fetch()['cnt'];

		// Stale Feeds (kein Update seit X Tagen)
		$sth = $this->pdo->prepare("SELECT COUNT(*) AS cnt FROM ttrss_feeds
			WHERE owner_uid = ? AND last_error = ''
			AND (last_successful_update IS NULL
				OR last_successful_update < NOW() - INTERVAL '1 day' * ?)");
		$sth->execute([$uid, $staleness_days]);
		$data['summary']['stale'] = (int)$sth->fetch()['cnt'];

		$data['summary']['healthy'] = $data['summary']['total']
			- $data['summary']['error'] - $data['summary']['stale'];
		$data['summary']['healthy'] = max(0, $data['summary']['healthy']);

		// Problem-Feeds (Fehler + Stale), sortiert nach Schwere
		$sth = $this->pdo->prepare("SELECT f.id, f.title, f.feed_url, f.last_error,
				f.last_updated, f.last_successful_update,
				CASE
					WHEN f.last_error != '' THEN 'error'
					WHEN f.last_successful_update IS NULL
						OR f.last_successful_update < NOW() - INTERVAL '1 day' * ? THEN 'stale'
					ELSE 'ok'
				END AS health_status
			FROM ttrss_feeds f
			WHERE f.owner_uid = ?
			AND (f.last_error != ''
				OR f.last_successful_update IS NULL
				OR f.last_successful_update < NOW() - INTERVAL '1 day' * ?)
			ORDER BY
				CASE WHEN f.last_error != '' THEN 0 ELSE 1 END,
				f.last_successful_update ASC NULLS FIRST
			LIMIT 50");
		$sth->execute([$staleness_days, $uid, $staleness_days]);

		while ($row = $sth->fetch()) {
			$data['problem_feeds'][] = [
				'id' => (int)$row['id'],
				'title' => $row['title'],
				'feed_url' => $row['feed_url'],
				'status' => $row['health_status'],
				'error' => $row['last_error'] ?: null,
				'last_updated' => $row['last_updated'],
				'last_success' => $row['last_successful_update'],
			];
		}

		// Letzte 20 Fehler-Einträge
		$sth = $this->pdo->prepare("SELECT fh.check_time, fh.status, fh.error_message,
				f.title AS feed_title
			FROM ttrss_plugin_feed_health fh
			JOIN ttrss_feeds f ON f.id = fh.feed_id
			WHERE fh.owner_uid = ? AND fh.status != 'ok'
			ORDER BY fh.check_time DESC LIMIT 20");
		$sth->execute([$uid]);

		while ($row = $sth->fetch()) {
			$data['recent_errors'][] = [
				'time' => $row['check_time'],
				'feed' => $row['feed_title'],
				'status' => $row['status'],
				'error' => $row['error_message'],
			];
		}

		print json_encode($data);
	}

	/**
	 * Verlauf für einen einzelnen Feed.
	 */
	function get_feed_history(): void {
		$feed_id = (int)clean($_REQUEST['feed_id'] ?? 0);
		if ($feed_id <= 0) {
			print json_encode(["error" => "invalid"]);
			return;
		}

		$sth = $this->pdo->prepare("SELECT check_time, status, error_message
			FROM ttrss_plugin_feed_health
			WHERE feed_id = ? AND owner_uid = ?
			ORDER BY check_time DESC LIMIT 50");
		$sth->execute([$feed_id, $_SESSION['uid']]);

		$history = [];
		while ($row = $sth->fetch()) {
			$history[] = [
				'time' => $row['check_time'],
				'status' => $row['status'],
				'error' => $row['error_message'],
			];
		}

		print json_encode($history);
	}

	/**
	 * Health-Score für einen Feed berechnen (0-100).
	 */
	private function calculate_health_score(int $feed_id, int $uid): int {
		$sth = $this->pdo->prepare("SELECT status, COUNT(*) AS cnt
			FROM ttrss_plugin_feed_health
			WHERE feed_id = ? AND owner_uid = ?
			AND check_time > NOW() - INTERVAL '7 days'
			GROUP BY status");
		$sth->execute([$feed_id, $uid]);

		$total = 0;
		$ok = 0;
		while ($row = $sth->fetch()) {
			$cnt = (int)$row['cnt'];
			$total += $cnt;
			if ($row['status'] === 'ok') $ok = $cnt;
		}

		if ($total === 0) return 50; // Keine Daten
		return (int)round(($ok / $total) * 100);
	}

	/**
	 * Housekeeping: Alte Einträge löschen, Staleness prüfen.
	 */
	function hook_house_keeping() {
		$retention_days = (int)($this->host->get($this, "retention_days", 90));

		$sth = $this->pdo->prepare("DELETE FROM ttrss_plugin_feed_health
			WHERE check_time < NOW() - INTERVAL '1 day' * ?");
		$sth->execute([$retention_days]);
	}

	/**
	 * Per-Feed Health-Info im Feed-Editor anzeigen.
	 * @param int $feed_id
	 */
	function hook_prefs_edit_feed($feed_id) {
		$feed_id = (int)$feed_id;
		$score = $this->calculate_health_score($feed_id, $_SESSION['uid']);

		$color = $score >= 80 ? '#40c463' : ($score >= 50 ? '#f0ad4e' : '#d9534f');

		?>
		<header><?= __('Feed-Gesundheit') ?></header>
		<fieldset>
			<label><?= __('Health-Score (7 Tage):') ?></label>
			<span style="font-weight: bold; color: <?= $color ?>;"><?= $score ?>%</span>
			<button dojoType="dijit.form.Button"
				onclick="Plugins.Feed_Health.showFeedHistory(<?= $feed_id ?>)">
				<?= __('Verlauf anzeigen') ?>
			</button>
		</fieldset>
		<?php
	}

	function hook_prefs_tab($args) {
		if ($args != "prefPrefs") return;

		$staleness = (int)($this->host->get($this, "staleness_days", 14));
		$retention = (int)($this->host->get($this, "retention_days", 90));

		?>
		<div dojoType="dijit.layout.AccordionPane"
			title="<i class='material-icons'>monitor_heart</i> <?= __('Feed-Gesundheit') ?>">

			<form dojoType="dijit.form.Form">
				<?= \Controls\pluginhandler_tags($this, "save_prefs") ?>

				<script type="dojo/method" event="onSubmit" args="evt">
					evt.preventDefault();
					if (this.validate()) {
						Notify.progress('Einstellungen werden gespeichert...', true);
						xhr.post("backend.php", this.getValues(), (reply) => {
							Notify.info(reply);
						})
					}
				</script>

				<fieldset>
					<label><?= __('Staleness-Schwellwert (Tage ohne Update):') ?></label>
					<input dojoType="dijit.form.NumberSpinner"
						name="staleness_days"
						value="<?= $staleness ?>"
						constraints="{min: 1, max: 365}"
						style="width: 100px">
				</fieldset>
				<fieldset>
					<label><?= __('Verlauf-Aufbewahrung (Tage):') ?></label>
					<input dojoType="dijit.form.NumberSpinner"
						name="retention_days"
						value="<?= $retention ?>"
						constraints="{min: 7, max: 365}"
						style="width: 100px">
				</fieldset>

				<hr/>
				<?= \Controls\submit_tag(__("Speichern")) ?>
			</form>

			<hr/>
			<p>
				<button dojoType="dijit.form.Button"
					onclick="Plugins.Feed_Health.showDashboard()">
					<i class="material-icons">monitor_heart</i> <?= __('Dashboard öffnen') ?>
				</button>
			</p>
		</div>
		<?php
	}

	function save_prefs(): void {
		$this->host->set($this, "staleness_days", (int)clean($_POST["staleness_days"] ?? 14));
		$this->host->set($this, "retention_days", (int)clean($_POST["retention_days"] ?? 90));
		echo __("Einstellungen gespeichert.");
	}

	function api_version() {
		return 2;
	}
}
