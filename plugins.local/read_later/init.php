<?php
class Read_Later extends Plugin {

	/** @var PluginHost $host */
	private $host;

	/** @var string Name des Read-Later-Labels */
	const LABEL_NAME = '📌 Später lesen';

	function about() {
		return [1.0,
			"Read-Later-Funktion über ein dediziertes Label mit Hotkey-Unterstützung",
			"tt-ss",
			false];
	}

	function init($host) {
		$this->host = $host;

		$host->add_hook($host::HOOK_ARTICLE_BUTTON, $this);
		$host->add_hook($host::HOOK_HOTKEY_MAP, $this);
		$host->add_hook($host::HOOK_HOTKEY_INFO, $this);

		// Label beim ersten Init erstellen
		$this->ensure_label_exists();
	}

	function get_js() {
		return file_get_contents(__DIR__ . "/read_later.js");
	}

	function get_css() {
		return file_get_contents(__DIR__ . "/read_later.css");
	}

	/**
	 * Stellt sicher, dass das Read-Later-Label existiert.
	 */
	private function ensure_label_exists(): void {
		if (empty($_SESSION['uid'])) return;

		$label_id = Labels::find_id(self::LABEL_NAME, $_SESSION['uid']);
		if (!$label_id) {
			Labels::create(self::LABEL_NAME, '#ffffff', '#2196f3', $_SESSION['uid']);
		}
	}

	/**
	 * Prüft ob ein Artikel das Read-Later-Label hat.
	 * @param int $article_id
	 * @return bool
	 */
	private function is_read_later(int $article_id): bool {
		$label_id = Labels::find_id(self::LABEL_NAME, $_SESSION['uid']);
		if (!$label_id) return false;

		$sth = $this->pdo->prepare("SELECT article_id FROM ttrss_user_labels2
			WHERE label_id = ? AND article_id = ? LIMIT 1");
		$sth->execute([$label_id, $article_id]);

		return $sth->fetch() !== false;
	}

	function hook_article_button($line) {
		$id = $line['id'];
		$is_saved = $this->is_read_later($id);
		$icon = $is_saved ? 'bookmark' : 'bookmark_border';
		$title = $is_saved ? __('Aus Leseliste entfernen') : __('Später lesen');

		return "<i class='material-icons read-later-btn " . ($is_saved ? 'read-later-active' : '') . "'
			data-article-id='$id'
			onclick=\"Plugins.Read_Later.toggle($id)\"
			style='cursor: pointer'
			title=\"$title\">$icon</i>";
	}

	function hook_hotkey_map($hotkeys) {
		$hotkeys["l"] = "read_later_toggle";
		return $hotkeys;
	}

	function hook_hotkey_info($hotkeys) {
		$hotkeys[__("Article")] .= "<li>" . __("<kbd>l</kbd> - Später lesen umschalten") . "</li>";
		return $hotkeys;
	}

	/**
	 * Toggle Read-Later-Status via AJAX.
	 */
	function toggle(): void {
		$id = (int) clean($_REQUEST['id'] ?? 0);
		if ($id <= 0) return;

		$owner_uid = $_SESSION['uid'];

		if ($this->is_read_later($id)) {
			Labels::remove_article($id, self::LABEL_NAME, $owner_uid);
			$is_saved = false;
		} else {
			Labels::add_article($id, self::LABEL_NAME, $owner_uid);
			$is_saved = true;
		}

		print json_encode([
			"id" => $id,
			"saved" => $is_saved
		]);
	}

	function api_version() {
		return 2;
	}
}
