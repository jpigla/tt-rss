<?php
class Feed_Discovery extends Plugin {

	/** @var PluginHost $host */
	private $host;

	/** @var array<string> Deutsche und englische Stoppwörter */
	private array $stopwords = [
		// Deutsch
		'der', 'die', 'das', 'den', 'dem', 'des', 'ein', 'eine', 'einer', 'einem', 'einen',
		'und', 'oder', 'aber', 'wenn', 'weil', 'dass', 'als', 'auch', 'noch', 'nur',
		'ist', 'sind', 'war', 'hat', 'haben', 'wird', 'werden', 'kann', 'können',
		'mit', 'von', 'für', 'auf', 'aus', 'bei', 'nach', 'über', 'unter', 'vor',
		'sich', 'nicht', 'wie', 'was', 'wer', 'ich', 'sie', 'wir', 'ihr', 'man',
		'mehr', 'neue', 'neuen', 'neuer', 'neues', 'neue', 'jetzt', 'schon', 'seit',
		'sehr', 'zum', 'zur', 'ins', 'diese', 'dieser', 'dieses', 'diesem', 'diesen',
		// Englisch
		'the', 'and', 'for', 'are', 'but', 'not', 'you', 'all', 'can', 'had',
		'her', 'was', 'one', 'our', 'out', 'has', 'his', 'how', 'its', 'may',
		'new', 'now', 'old', 'see', 'way', 'who', 'did', 'get', 'let', 'say',
		'she', 'too', 'use', 'from', 'have', 'been', 'some', 'them', 'than',
		'that', 'this', 'what', 'when', 'will', 'with', 'more', 'just', 'also',
		'into', 'your', 'most', 'over', 'such', 'make', 'like', 'here', 'many',
		'about', 'would', 'could', 'other', 'which', 'their', 'there', 'first',
		'after', 'where', 'those', 'being', 'these', 'should', 'before', 'between',
	];

	function about() {
		return [1.0,
			"Trending Topics aus abonnierten Feeds und Feed-Empfehlungen",
			"tt-ss",
			false];
	}

	function init($host) {
		$this->host = $host;

		$host->add_hook($host::HOOK_ARTICLE_FILTER, $this);
		$host->add_hook($host::HOOK_HOUSE_KEEPING, $this);
		$host->add_hook($host::HOOK_MAIN_TOOLBAR_BUTTON, $this);
		$host->add_hook($host::HOOK_PREFS_TAB, $this);

		$m = new Db_Migrations();
		$m->initialize_for_plugin($this);
		$m->migrate();
	}

	function get_js() {
		return file_get_contents(__DIR__ . "/feed_discovery.js");
	}

	function get_css() {
		return file_get_contents(__DIR__ . "/feed_discovery.css");
	}

	function get_prefs_js() {
		return file_get_contents(__DIR__ . "/feed_discovery.js");
	}

	/**
	 * Keywords aus Artikel-Titeln extrahieren (beim Import).
	 * @param array<string, mixed> $article
	 * @return array<string, mixed>
	 */
	function hook_article_filter($article) {
		// Leichtgewichtiges Keyword-Tracking über Plugin-Storage
		$title = mb_strtolower($article['title'] ?? '');
		$words = $this->extract_keywords($title);

		if (!empty($words)) {
			$today = date('Y-m-d');
			$key = "keywords_$today";
			$existing = $this->host->get($this, $key, []);

			foreach ($words as $word) {
				$existing[$word] = ($existing[$word] ?? 0) + 1;
			}

			// Nur Top 500 Keywords behalten
			arsort($existing);
			$existing = array_slice($existing, 0, 500, true);

			$this->host->set($this, $key, $existing);
		}

		return $article;
	}

	/**
	 * Keywords aus Text extrahieren.
	 * @return array<string>
	 */
	private function extract_keywords(string $text): array {
		// Sonderzeichen entfernen, auf Wörter aufteilen
		$text = preg_replace('/[^a-zäöüß\s-]/u', '', $text);
		$words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);

		$result = [];
		foreach ($words as $word) {
			$word = trim($word, '-');
			if (mb_strlen($word) < 3 || mb_strlen($word) > 50) continue;
			if (in_array($word, $this->stopwords)) continue;
			$result[] = $word;
		}

		return $result;
	}

	/**
	 * Toolbar-Button.
	 * @return string
	 */
	function hook_main_toolbar_button() {
		return "<i class='material-icons fd-toolbar-btn'
			onclick=\"Plugins.Feed_Discovery.showPanel()\"
			style='cursor: pointer'
			title=\"" . __('Entdecken') . "\">explore</i>";
	}

	/**
	 * Housekeeping: Trending Topics berechnen, alte Daten bereinigen.
	 */
	function hook_house_keeping() {
		// Batch-Limit: max. 50 Nutzer pro Durchlauf
		$sth = $this->pdo->prepare("SELECT id FROM ttrss_users LIMIT 50");
		$sth->execute();

		while ($user = $sth->fetch()) {
			$this->compute_trending((int)$user['id']);
		}

		// Alte Topics bereinigen (> 90 Tage)
		$this->pdo->prepare("DELETE FROM ttrss_plugin_trending_topics
			WHERE period_end < CURRENT_DATE - INTERVAL '90 days'")->execute();

		// Alte Suggestions bereinigen (> 30 Tage, abgelehnt)
		$this->pdo->prepare("DELETE FROM ttrss_plugin_feed_suggestions
			WHERE dismissed = true AND created_at < NOW() - INTERVAL '30 days'")->execute();
	}

	/**
	 * Trending Topics für einen Nutzer berechnen.
	 */
	private function compute_trending(int $uid): void {
		$today = date('Y-m-d');

		// Bereits heute berechnet?
		$sth = $this->pdo->prepare("SELECT id FROM ttrss_plugin_trending_topics
			WHERE owner_uid = ? AND period_end = ? LIMIT 1");
		$sth->execute([$uid, $today]);
		if ($sth->fetch()) return;

		// Keywords der letzten 7 Tage sammeln
		$current_week = [];
		$previous_week = [];

		// Daten werden über PluginHost-Storage gehalten (pro User)
		// Wir müssen den Storage mit dem richtigen UID laden
		// Alternativ: direkt aus ttrss_entries über PostgreSQL tsvector
		$period_days = (int)($this->host->get($this, "trending_period", 7));

		// PostgreSQL tsvector-basierte Keyword-Extraktion
		// ts_stat() benötigt ein String-Literal — PDO-Platzhalter funktionieren
		// nicht in $$-Blöcken. Werte werden strikt als int gecastet und validiert.
		$uid_safe = abs(intval($uid));
		$period_safe = max(1, min(90, intval($period_days)));
		if ($uid_safe < 1) return;
		$sql = "SELECT word, ndoc AS cnt FROM ts_stat($$
			SELECT tsvector_combined FROM ttrss_entries e
			JOIN ttrss_user_entries ue ON ue.ref_id = e.id
			WHERE ue.owner_uid = " . $uid_safe . "
			AND e.date_entered > NOW() - INTERVAL '" . $period_safe . " days'
		$$) ORDER BY ndoc DESC LIMIT 100";

		try {
			$sth = $this->pdo->query($sql);

			while ($row = $sth->fetch()) {
				$word = $row['word'];
				if (mb_strlen($word) < 3 || in_array($word, $this->stopwords)) continue;
				$current_week[$word] = (int)$row['cnt'];
			}
		} catch (\PDOException $e) {
			// tsvector_combined könnte nicht existieren — Fallback auf Plugin-Storage
			for ($i = 0; $i < $period_days; $i++) {
				$day = date('Y-m-d', strtotime("-{$i} days"));
				$day_data = $this->host->get($this, "keywords_$day", []);
				if (!is_array($day_data)) continue;

				foreach ($day_data as $word => $cnt) {
					$current_week[$word] = ($current_week[$word] ?? 0) + (int)$cnt;
				}
			}
		}

		// Vorherige Periode
		try {
			$prev_start = max(1, min(180, intval($period_days * 2)));
			$prev_end = max(1, min(90, intval($period_days)));
			$sql_prev = "SELECT word, ndoc AS cnt FROM ts_stat($$
				SELECT tsvector_combined FROM ttrss_entries e
				JOIN ttrss_user_entries ue ON ue.ref_id = e.id
				WHERE ue.owner_uid = " . $uid_safe . "
				AND e.date_entered BETWEEN NOW() - INTERVAL '" . $prev_start . " days'
					AND NOW() - INTERVAL '" . $prev_end . " days'
			$$) ORDER BY ndoc DESC LIMIT 100";
			$sth = $this->pdo->query($sql_prev);

			while ($row = $sth->fetch()) {
				$word = $row['word'];
				if (mb_strlen($word) < 3) continue;
				$previous_week[$word] = (int)$row['cnt'];
			}
		} catch (\PDOException $e) {
			for ($i = $period_days; $i < $period_days * 2; $i++) {
				$day = date('Y-m-d', strtotime("-{$i} days"));
				$day_data = $this->host->get($this, "keywords_$day", []);
				if (!is_array($day_data)) continue;

				foreach ($day_data as $word => $cnt) {
					$previous_week[$word] = ($previous_week[$word] ?? 0) + (int)$cnt;
				}
			}
		}

		if (empty($current_week)) return;

		// Trending = Wörter mit deutlichem Anstieg
		$min_articles = (int)($this->host->get($this, "min_articles", 3));
		$trending = [];

		foreach ($current_week as $word => $cnt) {
			if ($cnt < $min_articles) continue;

			$prev = $previous_week[$word] ?? 0;
			$growth = $prev > 0 ? ($cnt - $prev) / $prev : ($cnt >= $min_articles ? 1.0 : 0);

			if ($growth >= 0.3 || ($prev === 0 && $cnt >= $min_articles)) {
				$trending[$word] = [
					'count' => $cnt,
					'growth' => $growth,
				];
			}
		}

		// Nach Relevanz sortieren (count * growth)
		uasort($trending, function($a, $b) {
			return ($b['count'] * (1 + $b['growth'])) <=> ($a['count'] * (1 + $a['growth']));
		});

		$trending = array_slice($trending, 0, 20, true);

		$period_start = date('Y-m-d', strtotime("-{$period_days} days"));

		// Beispiel-Artikel für jedes Topic finden
		foreach ($trending as $word => $data) {
			$sample_sth = $this->pdo->prepare("SELECT e.id FROM ttrss_entries e
				JOIN ttrss_user_entries ue ON ue.ref_id = e.id
				WHERE ue.owner_uid = ?
				AND e.date_entered > NOW() - INTERVAL '1 day' * ?
				AND lower(e.title) LIKE ?
				ORDER BY e.date_entered DESC LIMIT 3");
			$word_escaped = str_replace(['%', '_'], ['\%', '\_'], $word);
			$sample_sth->execute([$uid, $period_days, '%' . $word_escaped . '%']);

			$sample_ids = [];
			while ($sr = $sample_sth->fetch()) {
				$sample_ids[] = (int)$sr['id'];
			}

			$sth2 = $this->pdo->prepare("INSERT INTO ttrss_plugin_trending_topics
				(owner_uid, topic, article_count, sample_ref_ids, period_start, period_end)
				VALUES (?, ?, ?, ?, ?, ?)");
			$sth2->execute([$uid, $word, $data['count'], implode(',', $sample_ids),
				$period_start, $today]);
		}

		// Alte Keyword-Storage bereinigen (> 14 Tage)
		for ($i = 15; $i < 30; $i++) {
			$old_day = date('Y-m-d', strtotime("-{$i} days"));
			$this->host->set($this, "keywords_$old_day", null);
		}
	}

	/**
	 * Trending Topics abrufen.
	 */
	function get_trending(): void {
		$uid = $_SESSION['uid'];

		$sth = $this->pdo->prepare("SELECT topic, article_count, sample_ref_ids,
				period_start, period_end
			FROM ttrss_plugin_trending_topics
			WHERE owner_uid = ?
			ORDER BY period_end DESC, article_count DESC
			LIMIT 30");
		$sth->execute([$uid]);

		$topics = [];
		$seen = [];

		while ($row = $sth->fetch()) {
			// Duplikate über Perioden vermeiden
			if (isset($seen[$row['topic']])) continue;
			$seen[$row['topic']] = true;

			$sample_titles = [];
			$sample_ids = array_filter(explode(',', $row['sample_ref_ids']));
			if (!empty($sample_ids)) {
				$placeholders = implode(',', array_fill(0, count($sample_ids), '?'));
				$sth2 = $this->pdo->prepare("SELECT title FROM ttrss_entries
					WHERE id IN ($placeholders) LIMIT 3");
				$sth2->execute(array_map('intval', $sample_ids));
				while ($sr = $sth2->fetch()) {
					$sample_titles[] = $sr['title'];
				}
			}

			$topics[] = [
				'topic' => $row['topic'],
				'count' => (int)$row['article_count'],
				'period' => $row['period_start'] . ' – ' . $row['period_end'],
				'samples' => $sample_titles,
			];
		}

		print json_encode(array_slice($topics, 0, 20));
	}

	/**
	 * Feed-Vorschläge abrufen.
	 */
	function get_suggestions(): void {
		$uid = $_SESSION['uid'];

		$sth = $this->pdo->prepare("SELECT id, feed_url, feed_title, site_url,
				reason, score
			FROM ttrss_plugin_feed_suggestions
			WHERE owner_uid = ? AND dismissed = false AND subscribed = false
			ORDER BY score DESC, created_at DESC LIMIT 20");
		$sth->execute([$uid]);

		$suggestions = [];
		while ($row = $sth->fetch()) {
			$suggestions[] = $row;
		}

		// Falls keine gespeicherten Vorschläge: aus Feed-Browser generieren
		if (empty($suggestions)) {
			$suggestions = $this->generate_suggestions_from_browser($uid);
		}

		print json_encode($suggestions);
	}

	/**
	 * Vorschläge aus dem TT-RSS Feed-Browser generieren.
	 * @return array<array<string, mixed>>
	 */
	private function generate_suggestions_from_browser(int $uid): array {
		$suggestions = [];

		// Feeds aus feedbrowser_cache, die der User noch nicht abonniert hat
		try {
			$sth = $this->pdo->prepare("SELECT fb.feed_url, fb.title, fb.site_url, fb.subscribers
				FROM ttrss_feedbrowser_cache fb
				WHERE fb.subscribers > 1
				AND fb.feed_url NOT IN (
					SELECT feed_url FROM ttrss_feeds WHERE owner_uid = ?
				)
				ORDER BY fb.subscribers DESC
				LIMIT 15");
			$sth->execute([$uid]);

			while ($row = $sth->fetch()) {
				$suggestions[] = [
					'id' => 0,
					'feed_url' => $row['feed_url'],
					'feed_title' => $row['title'] ?: $row['feed_url'],
					'site_url' => $row['site_url'] ?? '',
					'reason' => sprintf(__('%d andere Nutzer abonniert'), (int)$row['subscribers']),
					'score' => (float)$row['subscribers'],
				];
			}
		} catch (\PDOException $e) {
			// Tabelle existiert möglicherweise nicht
		}

		return $suggestions;
	}

	/**
	 * Vorschlag ablehnen.
	 */
	function dismiss_suggestion(): void {
		$id = (int)clean($_REQUEST['suggestion_id'] ?? 0);

		if ($id > 0) {
			$sth = $this->pdo->prepare("UPDATE ttrss_plugin_feed_suggestions
				SET dismissed = true WHERE id = ? AND owner_uid = ?");
			$sth->execute([$id, $_SESSION['uid']]);
		}

		print json_encode(["ok" => true]);
	}

	/**
	 * Vorgeschlagenen Feed abonnieren.
	 */
	function subscribe_suggestion(): void {
		$id = (int)clean($_REQUEST['suggestion_id'] ?? 0);
		$feed_url = clean($_REQUEST['feed_url'] ?? '');

		if (empty($feed_url)) {
			print json_encode(["error" => __("Keine Feed-URL angegeben.")]);
			return;
		}

		// Feed abonnieren über Feeds-Klasse
		$result = Feeds::subscribe_to_feed($feed_url);

		if ($id > 0) {
			$sth = $this->pdo->prepare("UPDATE ttrss_plugin_feed_suggestions
				SET subscribed = true WHERE id = ? AND owner_uid = ?");
			$sth->execute([$id, $_SESSION['uid']]);
		}

		if (is_numeric($result) && $result > 0) {
			print json_encode(["message" => __("Feed erfolgreich abonniert.")]);
		} else {
			print json_encode(["error" => sprintf(__("Fehler beim Abonnieren: %s"), $result)]);
		}
	}

	function hook_prefs_tab($args) {
		if ($args != "prefPrefs") return;

		$period = (int)($this->host->get($this, "trending_period", 7));
		$min = (int)($this->host->get($this, "min_articles", 3));

		?>
		<div dojoType="dijit.layout.AccordionPane"
			title="<i class='material-icons'>explore</i> <?= __('Feed-Entdecken') ?>">

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
					<label><?= __('Trending-Zeitraum (Tage):') ?></label>
					<input dojoType="dijit.form.NumberSpinner"
						name="trending_period"
						value="<?= $period ?>"
						constraints="{min: 1, max: 30}"
						style="width: 80px">
				</fieldset>
				<fieldset>
					<label><?= __('Mindest-Artikelzahl für Trending:') ?></label>
					<input dojoType="dijit.form.NumberSpinner"
						name="min_articles"
						value="<?= $min ?>"
						constraints="{min: 1, max: 50}"
						style="width: 80px">
				</fieldset>

				<hr/>
				<?= \Controls\submit_tag(__("Speichern")) ?>
			</form>

			<hr/>
			<p>
				<button dojoType="dijit.form.Button"
					onclick="Plugins.Feed_Discovery.showPanel()">
					<i class="material-icons">explore</i> <?= __('Entdecken-Panel öffnen') ?>
				</button>
			</p>
		</div>
		<?php
	}

	function save_prefs(): void {
		$this->host->set($this, "trending_period", (int)clean($_POST["trending_period"] ?? 7));
		$this->host->set($this, "min_articles", (int)clean($_POST["min_articles"] ?? 3));
		echo __("Einstellungen gespeichert.");
	}

	function api_version() {
		return 2;
	}
}
