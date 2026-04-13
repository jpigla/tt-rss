<?php
class Archive_Articles extends Plugin {

	/** @var PluginHost $host */
	private $host;

	function about() {
		return [1.0,
			"Artikel archivieren und dearchivieren mit Feed-Icon-Erhalt",
			"tt-ss",
			false];
	}

	function init($host) {
		$this->host = $host;

		$host->add_hook($host::HOOK_PREFS_TAB, $this);
	}

	function get_js() {
		return file_get_contents(__DIR__ . "/archive_articles.js");
	}

	function get_css() {
		return file_get_contents(__DIR__ . "/archive_articles.css");
	}

	/**
	 * Artikel archivieren: feed_id auf NULL setzen, orig_feed_id merken.
	 * Aufgerufen via PluginHandler: op=PluginHandler&plugin=archive_articles&method=archiveSelection
	 */
	function archiveSelection(): void {
		$ids = $this->_param_to_ids();
		if (!$ids) return;

		$ids_qmarks = arr_qmarks($ids);
		$pdo = Db::pdo();

		// Feed-Metadaten in ttrss_archived_feeds sichern (FK-Constraint)
		$sth = $pdo->prepare("SELECT DISTINCT feed_id FROM ttrss_user_entries
			WHERE ref_id IN ($ids_qmarks) AND owner_uid = ? AND feed_id IS NOT NULL");
		$sth->execute([...$ids, $_SESSION['uid']]);

		while ($row = $sth->fetch()) {
			$feed_id = (int) $row['feed_id'];

			$check = $pdo->prepare("SELECT id FROM ttrss_archived_feeds WHERE id = ?");
			$check->execute([$feed_id]);

			if (!$check->fetch()) {
				$fsth = $pdo->prepare("SELECT title, feed_url, site_url FROM ttrss_feeds WHERE id = ?");
				$fsth->execute([$feed_id]);

				if ($frow = $fsth->fetch()) {
					$isth = $pdo->prepare("INSERT INTO ttrss_archived_feeds
						(id, owner_uid, created, title, feed_url, site_url)
						VALUES (?, ?, NOW(), ?, ?, ?)");
					$isth->execute([$feed_id, $_SESSION['uid'], $frow['title'], $frow['feed_url'], $frow['site_url']]);
				}
			}
		}

		// Archivieren: feed_id = NULL, orig_feed_id merken
		$sth = $pdo->prepare("UPDATE ttrss_user_entries SET
			orig_feed_id = COALESCE(orig_feed_id, feed_id),
			feed_id = NULL
			WHERE ref_id IN ($ids_qmarks) AND owner_uid = ? AND feed_id IS NOT NULL");

		$sth->execute([...$ids, $_SESSION['uid']]);

		print json_encode(["message" => "UPDATE_COUNTERS"]);
	}

	/**
	 * Artikel dearchivieren: feed_id aus orig_feed_id wiederherstellen.
	 */
	function unarchiveSelection(): void {
		$ids = $this->_param_to_ids();
		if (!$ids) return;

		$ids_qmarks = arr_qmarks($ids);
		$pdo = Db::pdo();

		// Wiederherstellen wenn Original-Feed noch existiert
		$sth = $pdo->prepare("UPDATE ttrss_user_entries SET
			feed_id = ttrss_user_entries.orig_feed_id,
			orig_feed_id = NULL
			FROM ttrss_feeds
			WHERE ttrss_user_entries.ref_id IN ($ids_qmarks)
			AND ttrss_user_entries.owner_uid = ?
			AND ttrss_user_entries.feed_id IS NULL
			AND ttrss_user_entries.orig_feed_id = ttrss_feeds.id
			AND ttrss_feeds.owner_uid = ttrss_user_entries.owner_uid");

		$sth->execute([...$ids, $_SESSION['uid']]);

		// Fuer Artikel deren Feed geloescht wurde: orig_feed_id aufräumen
		$sth2 = $pdo->prepare("UPDATE ttrss_user_entries SET
			orig_feed_id = NULL
			WHERE ref_id IN ($ids_qmarks) AND owner_uid = ? AND feed_id IS NULL AND orig_feed_id IS NOT NULL
			AND orig_feed_id NOT IN (SELECT id FROM ttrss_feeds WHERE owner_uid = ?)");

		$sth2->execute([...$ids, $_SESSION['uid'], $_SESSION['uid']]);

		print json_encode(["message" => "UPDATE_COUNTERS"]);
	}

	private function _param_to_ids(): array {
		$raw = clean($_REQUEST['ids'] ?? '');
		if (empty($raw)) return [];

		return array_map('intval', array_filter(explode(',', $raw), 'strlen'));
	}

	function hook_prefs_tab($args) {
		// Keine Einstellungen noetig — Plugin ist aktiv wenn aktiviert
	}

	function api_version() {
		return 2;
	}
}
