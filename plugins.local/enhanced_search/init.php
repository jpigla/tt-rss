<?php
class Enhanced_Search extends Plugin {

	/** @var PluginHost $host */
	private $host;

	function about() {
		return [1.0,
			"Gespeicherte Suchen und erweiterte Suchoberfläche mit Datumsfiltern",
			"tt-ss",
			false];
	}

	function init($host) {
		$this->host = $host;

		$host->add_hook($host::HOOK_PREFS_TAB, $this);
		$host->add_hook($host::HOOK_TOOLBAR_BUTTON, $this);

		// DB-Migration ausführen
		$m = new Db_Migrations();
		$m->initialize_for_plugin($this);
		$m->migrate();
	}

	function get_js() {
		return file_get_contents(__DIR__ . "/enhanced_search.js");
	}

	function get_css() {
		return file_get_contents(__DIR__ . "/enhanced_search.css");
	}

	/**
	 * Toolbar-Button für gespeicherte Suchen.
	 */
	function hook_toolbar_button() {
		return "<i class='material-icons es-toolbar-btn'
			onclick=\"Plugins.Enhanced_Search.showSaved()\"
			style='cursor: pointer'
			title=\"" . __('Gespeicherte Suchen') . "\">saved_search</i>";
	}

	/**
	 * Einstellungs-Tab für gespeicherte Suchen.
	 */
	function hook_prefs_tab($args) {
		if ($args != "prefPrefs") return;
		?>
		<div dojoType="dijit.layout.AccordionPane"
			title="<i class='material-icons'>saved_search</i> <?= __('Gespeicherte Suchen') ?>">

			<div id="es-saved-searches-prefs">
				<h3><?= __('Gespeicherte Suchen verwalten') ?></h3>

				<div id="es-search-list">
					<?= __('Lade...') ?>
				</div>

				<hr/>

				<h3><?= __('Neue Suche speichern') ?></h3>

				<form id="es-add-search-form">
					<fieldset>
						<label><?= __('Titel:') ?></label>
						<input dojoType="dijit.form.TextBox" name="title" id="es-new-title"
							required="1" style="width: 200px" />
					</fieldset>
					<fieldset>
						<label><?= __('Suchabfrage:') ?></label>
						<input dojoType="dijit.form.TextBox" name="query" id="es-new-query"
							required="1" style="width: 300px" />
					</fieldset>
					<fieldset>
						<label><?= __('Feed-ID (optional):') ?></label>
						<input dojoType="dijit.form.TextBox" name="feed_id" id="es-new-feed-id"
							style="width: 80px" />
					</fieldset>
					<fieldset>
						<label><?= __('Kategorie-ID (optional):') ?></label>
						<input dojoType="dijit.form.TextBox" name="cat_id" id="es-new-cat-id"
							style="width: 80px" />
					</fieldset>
					<button dojoType="dijit.form.Button" type="button"
						onclick="Plugins.Enhanced_Search.addSearch()">
						<?= __('Suche speichern') ?>
					</button>
				</form>
			</div>

			<script type="text/javascript">
				require(['dojo/ready'], function(ready) {
					ready(function() {
						Plugins.Enhanced_Search.loadPrefsSearches();
					});
				});
			</script>
		</div>
		<?php
	}

	/**
	 * Aktuelle Suche speichern (AJAX-Handler).
	 */
	function save_search(): void {
		$title = clean($_REQUEST['title'] ?? '');
		$query = clean($_REQUEST['query'] ?? '');
		$feed_id = !empty($_REQUEST['feed_id']) ? (int) clean($_REQUEST['feed_id']) : null;
		$cat_id = !empty($_REQUEST['cat_id']) ? (int) clean($_REQUEST['cat_id']) : null;
		$owner_uid = $_SESSION['uid'];

		if (empty($title) || empty($query)) {
			print json_encode(['status' => 'error', 'message' => 'Titel und Suchabfrage sind erforderlich.']);
			return;
		}

		$sth = $this->pdo->prepare("INSERT INTO ttrss_plugin_saved_searches
			(owner_uid, title, query, feed_id, cat_id) VALUES (?, ?, ?, ?, ?)");
		$sth->execute([$owner_uid, $title, $query, $feed_id, $cat_id]);

		print json_encode(['status' => 'ok']);
	}

	/**
	 * Gespeicherte Suche löschen (AJAX-Handler).
	 */
	function delete_search(): void {
		$id = (int) clean($_REQUEST['id'] ?? 0);
		$owner_uid = $_SESSION['uid'];

		$sth = $this->pdo->prepare("DELETE FROM ttrss_plugin_saved_searches
			WHERE id = ? AND owner_uid = ?");
		$sth->execute([$id, $owner_uid]);

		print json_encode(['status' => 'ok']);
	}

	/**
	 * Alle gespeicherten Suchen als JSON zurückgeben (AJAX-Handler).
	 */
	function get_searches(): void {
		$owner_uid = $_SESSION['uid'];

		$sth = $this->pdo->prepare("SELECT id, title, query, feed_id, cat_id, created_at
			FROM ttrss_plugin_saved_searches
			WHERE owner_uid = ?
			ORDER BY created_at DESC");
		$sth->execute([$owner_uid]);

		$searches = [];
		while ($row = $sth->fetch(PDO::FETCH_ASSOC)) {
			$searches[] = $row;
		}

		print json_encode($searches);
	}

	/**
	 * Suche ausführen - leitet zur Suchansicht weiter.
	 */
	function execute_search(): void {
		$id = (int) clean($_REQUEST['id'] ?? 0);
		$owner_uid = $_SESSION['uid'];

		$sth = $this->pdo->prepare("SELECT query, feed_id FROM ttrss_plugin_saved_searches
			WHERE id = ? AND owner_uid = ?");
		$sth->execute([$id, $owner_uid]);

		$row = $sth->fetch(PDO::FETCH_ASSOC);
		if ($row) {
			print json_encode([
				'status' => 'ok',
				'query' => $row['query'],
				'feed_id' => $row['feed_id'] ?? -4
			]);
		} else {
			print json_encode(['status' => 'error', 'message' => 'Suche nicht gefunden.']);
		}
	}

	function api_version() {
		return 2;
	}
}
