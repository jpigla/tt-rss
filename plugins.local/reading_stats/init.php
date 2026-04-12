<?php
class Reading_Stats extends Plugin {

	/** @var PluginHost $host */
	private $host;

	function about() {
		return [1.0,
			"Lese-Statistiken, Streaks und Analytics-Dashboard",
			"tt-ss",
			false];
	}

	function init($host) {
		$this->host = $host;

		$host->add_hook($host::HOOK_RENDER_ARTICLE_CDM, $this);
		$host->add_hook($host::HOOK_RENDER_ARTICLE, $this);
		$host->add_hook($host::HOOK_MAIN_TOOLBAR_BUTTON, $this);
		$host->add_hook($host::HOOK_PREFS_TAB, $this);
		$host->add_hook($host::HOOK_HOUSE_KEEPING, $this);

		$m = new Db_Migrations();
		$m->initialize_for_plugin($this);
		$m->migrate();
	}

	function get_js() {
		return file_get_contents(__DIR__ . "/reading_stats.js");
	}

	function get_css() {
		return file_get_contents(__DIR__ . "/reading_stats.css");
	}

	function get_prefs_js() {
		return file_get_contents(__DIR__ . "/reading_stats.js");
	}

	/**
	 * Artikel mit Tracking-Daten versehen (CDM-Modus).
	 * @param array<string, mixed> $article
	 * @return array<string, mixed>
	 */
	function hook_render_article_cdm($article) {
		return $this->inject_tracking($article);
	}

	/**
	 * @param array<string, mixed> $article
	 * @return array<string, mixed>
	 */
	function hook_render_article($article) {
		return $this->inject_tracking($article);
	}

	/**
	 * @param array<string, mixed> $article
	 * @return array<string, mixed>
	 */
	private function inject_tracking(array $article): array {
		$id = $article['id'] ?? 0;
		if (!$id) return $article;

		// Wortanzahl aus dem Inhalt berechnen
		$content = strip_tags($article['content'] ?? '');
		$word_count = str_word_count($content);

		$feed_id = 0;
		$sth = $this->pdo->prepare("SELECT feed_id FROM ttrss_user_entries
			WHERE ref_id = ? AND owner_uid = ?");
		$sth->execute([$id, $_SESSION['uid']]);
		if ($row = $sth->fetch()) {
			$feed_id = (int)$row['feed_id'];
		}

		$article['content'] .= "<div class='rs-tracking-data' style='display:none'
			data-article-id='" . (int)$id . "'
			data-word-count='" . (int)$word_count . "'
			data-feed-id='" . (int)$feed_id . "'></div>";

		return $article;
	}

	/**
	 * Toolbar-Button für Statistik-Dashboard.
	 * @return string
	 */
	function hook_main_toolbar_button() {
		return "<i class='material-icons rs-dashboard-btn'
			onclick=\"Plugins.Reading_Stats.showDashboard()\"
			style='cursor: pointer'
			title=\"" . __('Lese-Statistiken') . "\">insights</i>";
	}

	/**
	 * Lese-Ereignis aufzeichnen.
	 */
	function record_read(): void {
		$ref_id = (int)clean($_REQUEST['ref_id'] ?? 0);
		$feed_id = (int)clean($_REQUEST['feed_id'] ?? 0);
		$word_count = (int)clean($_REQUEST['word_count'] ?? 0);

		if ($ref_id <= 0) {
			print json_encode(["error" => "invalid"]);
			return;
		}

		// Duplikate innerhalb der letzten Minute vermeiden
		$sth = $this->pdo->prepare("SELECT id FROM ttrss_plugin_reading_stats
			WHERE ref_id = ? AND owner_uid = ?
			AND read_at > NOW() - INTERVAL '1 minute'");
		$sth->execute([$ref_id, $_SESSION['uid']]);

		if (!$sth->fetch()) {
			$sth = $this->pdo->prepare("INSERT INTO ttrss_plugin_reading_stats
				(owner_uid, ref_id, feed_id, word_count, read_at)
				VALUES (?, ?, ?, ?, NOW())");
			$sth->execute([$_SESSION['uid'], $ref_id, $feed_id > 0 ? $feed_id : null, $word_count > 0 ? $word_count : null]);

			// Streak aktualisieren
			$this->update_streak($_SESSION['uid']);
		}

		print json_encode(["ok" => true]);
	}

	/**
	 * Lesezeit für einen Artikel aktualisieren.
	 */
	function record_reading_time(): void {
		$ref_id = (int)clean($_REQUEST['ref_id'] ?? 0);
		$seconds = min((int)clean($_REQUEST['seconds'] ?? 0), 120); // Max 2 Minuten pro Update

		if ($ref_id <= 0 || $seconds <= 0) {
			print json_encode(["error" => "invalid"]);
			return;
		}

		// Letzten Eintrag für diesen Artikel aktualisieren
		$sth = $this->pdo->prepare("UPDATE ttrss_plugin_reading_stats
			SET reading_time_sec = COALESCE(reading_time_sec, 0) + ?
			WHERE id = (
				SELECT id FROM ttrss_plugin_reading_stats
				WHERE ref_id = ? AND owner_uid = ?
				ORDER BY read_at DESC LIMIT 1
			)");
		$sth->execute([$seconds, $ref_id, $_SESSION['uid']]);

		print json_encode(["ok" => true]);
	}

	/**
	 * Dashboard-Daten abrufen.
	 */
	function get_dashboard(): void {
		$uid = $_SESSION['uid'];

		$data = [
			'today' => 0,
			'week' => 0,
			'month' => 0,
			'total' => 0,
			'avg_daily_7d' => 0,
			'total_reading_time_min' => 0,
			'streak' => ['current' => 0, 'longest' => 0],
			'daily_counts' => [],
			'hourly_distribution' => [],
			'top_feeds' => [],
			'heatmap' => [],
		];

		// Zähler
		$sth = $this->pdo->prepare("SELECT
			COUNT(*) FILTER (WHERE read_at::date = CURRENT_DATE) AS today,
			COUNT(*) FILTER (WHERE read_at > NOW() - INTERVAL '7 days') AS week,
			COUNT(*) FILTER (WHERE read_at > NOW() - INTERVAL '30 days') AS month,
			COUNT(*) AS total,
			COALESCE(SUM(reading_time_sec), 0) AS total_seconds
			FROM ttrss_plugin_reading_stats WHERE owner_uid = ?");
		$sth->execute([$uid]);
		$counts = $sth->fetch();

		$data['today'] = (int)$counts['today'];
		$data['week'] = (int)$counts['week'];
		$data['month'] = (int)$counts['month'];
		$data['total'] = (int)$counts['total'];
		$data['total_reading_time_min'] = round((int)$counts['total_seconds'] / 60);

		// Durchschnitt 7 Tage
		$sth = $this->pdo->prepare("SELECT COUNT(DISTINCT read_at::date) AS days,
			COUNT(*) AS articles
			FROM ttrss_plugin_reading_stats
			WHERE owner_uid = ? AND read_at > NOW() - INTERVAL '7 days'");
		$sth->execute([$uid]);
		$avg = $sth->fetch();
		$days = max(1, (int)$avg['days']);
		$data['avg_daily_7d'] = round((int)$avg['articles'] / $days, 1);

		// Streak
		$sth = $this->pdo->prepare("SELECT current_streak, longest_streak
			FROM ttrss_plugin_reading_streaks WHERE owner_uid = ?");
		$sth->execute([$uid]);
		if ($streak = $sth->fetch()) {
			$data['streak'] = [
				'current' => (int)$streak['current_streak'],
				'longest' => (int)$streak['longest_streak'],
			];
		}

		// Tägliche Zähler (letzte 30 Tage)
		$sth = $this->pdo->prepare("SELECT read_at::date AS day, COUNT(*) AS cnt
			FROM ttrss_plugin_reading_stats
			WHERE owner_uid = ? AND read_at > NOW() - INTERVAL '30 days'
			GROUP BY read_at::date ORDER BY day");
		$sth->execute([$uid]);
		while ($row = $sth->fetch()) {
			$data['daily_counts'][] = ['date' => $row['day'], 'count' => (int)$row['cnt']];
		}

		// Stunden-Verteilung
		$sth = $this->pdo->prepare("SELECT EXTRACT(HOUR FROM read_at)::int AS hour, COUNT(*) AS cnt
			FROM ttrss_plugin_reading_stats
			WHERE owner_uid = ? AND read_at > NOW() - INTERVAL '30 days'
			GROUP BY hour ORDER BY hour");
		$sth->execute([$uid]);
		$hours = array_fill(0, 24, 0);
		while ($row = $sth->fetch()) {
			$hours[(int)$row['hour']] = (int)$row['cnt'];
		}
		$data['hourly_distribution'] = $hours;

		// Top 10 Feeds
		$sth = $this->pdo->prepare("SELECT f.title, COUNT(*) AS cnt
			FROM ttrss_plugin_reading_stats rs
			JOIN ttrss_feeds f ON f.id = rs.feed_id
			WHERE rs.owner_uid = ? AND rs.feed_id IS NOT NULL
			AND rs.read_at > NOW() - INTERVAL '30 days'
			GROUP BY f.title ORDER BY cnt DESC LIMIT 10");
		$sth->execute([$uid]);
		while ($row = $sth->fetch()) {
			$data['top_feeds'][] = ['title' => $row['title'], 'count' => (int)$row['cnt']];
		}

		// Heatmap (letzte 52 Wochen)
		$sth = $this->pdo->prepare("SELECT read_at::date AS day, COUNT(*) AS cnt
			FROM ttrss_plugin_reading_stats
			WHERE owner_uid = ? AND read_at > NOW() - INTERVAL '52 weeks'
			GROUP BY read_at::date ORDER BY day");
		$sth->execute([$uid]);
		while ($row = $sth->fetch()) {
			$data['heatmap'][] = ['date' => $row['day'], 'count' => (int)$row['cnt']];
		}

		print json_encode($data);
	}

	/**
	 * Streak berechnen und aktualisieren.
	 */
	private function update_streak(int $uid): void {
		$today = date('Y-m-d');

		$sth = $this->pdo->prepare("SELECT current_streak, longest_streak, last_read_date
			FROM ttrss_plugin_reading_streaks WHERE owner_uid = ?");
		$sth->execute([$uid]);
		$existing = $sth->fetch();

		if ($existing) {
			$last_date = $existing['last_read_date'];
			$current = (int)$existing['current_streak'];
			$longest = (int)$existing['longest_streak'];

			if ($last_date === $today) {
				return; // Heute schon gezählt
			}

			$yesterday = date('Y-m-d', strtotime('-1 day'));
			if ($last_date === $yesterday) {
				$current++;
			} else {
				$current = 1; // Streak unterbrochen
			}

			$longest = max($longest, $current);

			$sth = $this->pdo->prepare("UPDATE ttrss_plugin_reading_streaks
				SET current_streak = ?, longest_streak = ?, last_read_date = ?, updated_at = NOW()
				WHERE owner_uid = ?");
			$sth->execute([$current, $longest, $today, $uid]);
		} else {
			$sth = $this->pdo->prepare("INSERT INTO ttrss_plugin_reading_streaks
				(owner_uid, current_streak, longest_streak, last_read_date)
				VALUES (?, 1, 1, ?)");
			$sth->execute([$uid, $today]);
		}
	}

	/**
	 * Housekeeping: Alte Daten bereinigen, Streaks aktualisieren.
	 */
	function hook_house_keeping() {
		$retention_days = (int)($this->host->get($this, "retention_days", 365));

		// Alte Einträge löschen
		$sth = $this->pdo->prepare("DELETE FROM ttrss_plugin_reading_stats
			WHERE read_at < NOW() - INTERVAL '1 day' * ?");
		$sth->execute([$retention_days]);

		// Streaks für alle Nutzer prüfen (unterbrochene zurücksetzen)
		$yesterday = date('Y-m-d', strtotime('-1 day'));
		$sth = $this->pdo->prepare("UPDATE ttrss_plugin_reading_streaks
			SET current_streak = 0, updated_at = NOW()
			WHERE last_read_date < ? AND current_streak > 0");
		$sth->execute([$yesterday]);
	}

	function hook_prefs_tab($args) {
		if ($args != "prefPrefs") return;

		$retention = (int)($this->host->get($this, "retention_days", 365));

		?>
		<div dojoType="dijit.layout.AccordionPane"
			title="<i class='material-icons'>insights</i> <?= __('Lese-Statistiken') ?>">

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
					<label><?= __('Aufbewahrungsdauer (Tage):') ?></label>
					<input dojoType="dijit.form.NumberSpinner"
						name="retention_days"
						value="<?= $retention ?>"
						constraints="{min: 30, max: 3650}"
						style="width: 100px">
				</fieldset>

				<hr/>
				<?= \Controls\submit_tag(__("Speichern")) ?>
			</form>

			<hr/>
			<p>
				<button dojoType="dijit.form.Button"
					onclick="Plugins.Reading_Stats.showDashboard()">
					<i class="material-icons">insights</i> <?= __('Dashboard öffnen') ?>
				</button>
			</p>
		</div>
		<?php
	}

	function save_prefs(): void {
		$this->host->set($this, "retention_days", (int)clean($_POST["retention_days"] ?? 365));
		echo __("Einstellungen gespeichert.");
	}

	function api_version() {
		return 2;
	}
}
