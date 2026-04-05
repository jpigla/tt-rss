<?php
class Bulk_Actions extends Plugin {

	/** @var PluginHost $host */
	private $host;

	function about() {
		return [1.0,
			"Erweiterte Massenoperationen: Taggen, Label zuweisen, als gelesen markieren",
			"tt-ss",
			false];
	}

	function init($host) {
		$this->host = $host;

		$host->add_hook($host::HOOK_HEADLINE_TOOLBAR_SELECT_MENU_ITEM2, $this);
	}

	function get_js() {
		return file_get_contents(__DIR__ . "/bulk_actions.js");
	}

	/**
	 * Menüeinträge für das Auswahl-Dropdown in der Headline-Toolbar.
	 */
	function hook_headline_toolbar_select_menu_item2($feed_id, $is_cat): string {
		$items = "";

		$items .= "<div dojoType='dijit.MenuItem' onclick='Plugins.Bulk_Actions.bulkTag()'>
			<i class='material-icons'>sell</i> " . __('Ausgewählte taggen...') . "
		</div>";

		$items .= "<div dojoType='dijit.MenuItem' onclick='Plugins.Bulk_Actions.bulkLabel()'>
			<i class='material-icons'>label</i> " . __('Label zuweisen...') . "
		</div>";

		$items .= "<div dojoType='dijit.MenuItem' onclick='Plugins.Bulk_Actions.markReadAndHide()'>
			<i class='material-icons'>visibility_off</i> " . __('Als gelesen markieren und ausblenden') . "
		</div>";

		return $items;
	}

	/**
	 * Tags zu ausgewählten Artikeln hinzufügen (AJAX-Handler).
	 */
	function bulk_tag(): void {
		$tags_str = clean($_REQUEST['tags'] ?? '');
		$ids = json_decode(clean($_REQUEST['ids'] ?? '[]'), true);

		if (empty($tags_str) || empty($ids)) {
			print json_encode(['status' => 'error', 'message' => 'Tags und Artikel-IDs erforderlich.']);
			return;
		}

		$tags = array_map('trim', explode(',', $tags_str));
		$owner_uid = $_SESSION['uid'];

		foreach ($ids as $id) {
			$id = (int) $id;
			foreach ($tags as $tag) {
				$tag = mb_strtolower(trim($tag));
				if (empty($tag)) continue;

				// Prüfen ob Tag bereits existiert
				$sth = $this->pdo->prepare("SELECT id FROM ttrss_tags
					WHERE tag_name = ? AND post_int_id = (
						SELECT int_id FROM ttrss_user_entries WHERE ref_id = ? AND owner_uid = ?
					) AND owner_uid = ?");
				$sth->execute([$tag, $id, $owner_uid, $owner_uid]);

				if (!$sth->fetch()) {
					$sth2 = $this->pdo->prepare("INSERT INTO ttrss_tags
						(tag_name, post_int_id, owner_uid)
						SELECT ?, int_id, ? FROM ttrss_user_entries
						WHERE ref_id = ? AND owner_uid = ?");
					$sth2->execute([$tag, $owner_uid, $id, $owner_uid]);
				}
			}
		}

		print json_encode(['status' => 'ok', 'tagged' => count($ids)]);
	}

	/**
	 * Label zu ausgewählten Artikeln zuweisen (AJAX-Handler).
	 */
	function bulk_label(): void {
		$label_id = (int) clean($_REQUEST['label_id'] ?? 0);
		$ids = json_decode(clean($_REQUEST['ids'] ?? '[]'), true);

		if (empty($label_id) || empty($ids)) {
			print json_encode(['status' => 'error', 'message' => 'Label-ID und Artikel-IDs erforderlich.']);
			return;
		}

		$owner_uid = $_SESSION['uid'];

		foreach ($ids as $id) {
			Labels::add_article((int) $id, $label_id, $owner_uid);
		}

		print json_encode(['status' => 'ok', 'labeled' => count($ids)]);
	}

	/**
	 * Verfügbare Labels als JSON zurückgeben (AJAX-Handler).
	 */
	function get_labels(): void {
		$owner_uid = $_SESSION['uid'];

		$sth = $this->pdo->prepare("SELECT id, caption, fg_color, bg_color
			FROM ttrss_labels2
			WHERE owner_uid = ?
			ORDER BY caption");
		$sth->execute([$owner_uid]);

		$labels = [];
		while ($row = $sth->fetch(PDO::FETCH_ASSOC)) {
			$labels[] = $row;
		}

		print json_encode($labels);
	}

	function api_version() {
		return 2;
	}
}
