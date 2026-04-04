<?php
class Magic_Sort extends Plugin {

	/** @var PluginHost $host */
	private $host;

	function about() {
		return [1.0,
			"Magische Sortierung: Artikel nach Relevanz sortieren",
			"tt-ss",
			false];
	}

	function init($host) {
		$this->host = $host;

		$host->add_hook($host::HOOK_HEADLINES_CUSTOM_SORT_MAP, $this);
		$host->add_hook($host::HOOK_HEADLINES_CUSTOM_SORT_OVERRIDE, $this);
		$host->add_hook($host::HOOK_HOUSE_KEEPING, $this);
	}

	/**
	 * Fügt "Magic" zum Sortierungs-Dropdown hinzu.
	 */
	function hook_headlines_custom_sort_map($sort_map) {
		$sort_map['magic'] = __('Magic (Relevanz)');
		return $sort_map;
	}

	/**
	 * Liefert das ORDER BY für Magic-Sortierung.
	 */
	function hook_headlines_custom_sort_override($order, $options) {
		if (($options['order_by'] ?? '') === 'magic') {
			return 'score DESC, date_entered DESC';
		}

		return $order;
	}

	/**
	 * Aktualisiert Scores basierend auf Feed-Engagement.
	 * Wird periodisch durch HOOK_HOUSE_KEEPING aufgerufen.
	 */
	function hook_house_keeping() {
		// In Daemon-Kontexten existiert keine Session -- alle Benutzer verarbeiten
		if (!empty($_SESSION['uid'])) {
			$this->update_scores_for_user((int)$_SESSION['uid']);
		} else {
			$sth = $this->pdo->query("SELECT id FROM ttrss_users");
			while ($row = $sth->fetch()) {
				$this->update_scores_for_user((int)$row['id']);
			}
		}
	}

	private function update_scores_for_user(int $owner_uid): void {
		if (!$owner_uid) return;

		// Letzte Ausführung prüfen (max. 1x pro Stunde)
		$last_run = $this->host->get($this, 'last_score_update', 0);
		if (time() - $last_run < 3600) return;

		$this->host->set($this, 'last_score_update', time());

		// Feed-Engagement berechnen: Wie viele Artikel hat der User
		// in den letzten 30 Tagen pro Feed gelesen?
		$sth = $this->pdo->prepare("SELECT feed_id, COUNT(*) AS read_count
			FROM ttrss_user_entries
			WHERE owner_uid = ?
			AND unread = false
			AND last_read > NOW() - INTERVAL '30 days'
			GROUP BY feed_id");
		$sth->execute([$owner_uid]);

		$engagement = [];
		$max_reads = 1;
		while ($row = $sth->fetch()) {
			$engagement[$row['feed_id']] = (int) $row['read_count'];
			$max_reads = max($max_reads, (int) $row['read_count']);
		}

		if (empty($engagement)) return;

		// Score für ungelesene Artikel basierend auf Feed-Engagement setzen
		// Score-Bereich: 0-10, wobei bestehende manuelle Scores erhalten bleiben
		foreach ($engagement as $feed_id => $read_count) {
			// Normalisierter Engagement-Score (0-5)
			$engagement_score = (int) round(($read_count / $max_reads) * 5);

			if ($engagement_score <= 0) continue;

			// Nur Artikel mit Score 0 aktualisieren (manuelle Scores nicht überschreiben)
			$update = $this->pdo->prepare("UPDATE ttrss_user_entries
				SET score = ?
				WHERE owner_uid = ?
				AND feed_id = ?
				AND score = 0
				AND unread = true");
			$update->execute([$engagement_score, $owner_uid, $feed_id]);
		}
	}

	function api_version() {
		return 2;
	}
}
