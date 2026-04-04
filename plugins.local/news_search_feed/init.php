<?php
class News_Search_Feed extends Plugin {

	/** @var PluginHost $host */
	private $host;

	function about() {
		return [1.0,
			"Google News Suchanfragen als RSS-Feeds abonnieren",
			"admin",
			false];
	}

	function init($host) {
		$this->host = $host;

		$host->add_hook($host::HOOK_PREFS_TAB, $this);
	}

	function hook_prefs_tab($args) {
		if ($args != "prefFeeds") return;

		?>
		<div dojoType="dijit.layout.AccordionPane"
			title="<i class='material-icons'>search</i> <?= __('Nachrichtensuche') ?>">

			<h3><?= __('Google News Suche als Feed abonnieren') ?></h3>

			<p><?= __('Geben Sie einen Suchbegriff ein, um einen Google News RSS-Feed zu abonnieren. Der Feed wird automatisch mit neuen Suchergebnissen aktualisiert.') ?></p>

			<form dojoType="dijit.form.Form">
				<?= \Controls\pluginhandler_tags($this, "subscribe_search") ?>

				<fieldset>
					<label><?= __('Suchbegriff:') ?></label>
					<input dojoType="dijit.form.ValidationTextBox"
						required="true"
						name="query"
						style="width: 300px"
						placeholder="z.B. Künstliche Intelligenz">
				</fieldset>

				<fieldset>
					<label><?= __('Sprache:') ?></label>
					<select dojoType="dijit.form.Select" name="lang">
						<option value="de" selected>Deutsch</option>
						<option value="en">English</option>
						<option value="fr">Français</option>
						<option value="es">Español</option>
						<option value="it">Italiano</option>
					</select>
				</fieldset>

				<hr/>

				<?= \Controls\submit_tag(__('Feed abonnieren')) ?>
			</form>
		</div>
		<?php
	}

	function subscribe_search() : void {
		$query = clean($_REQUEST['query'] ?? '');
		$lang = clean($_REQUEST['lang'] ?? 'de');

		if (empty($query)) {
			print json_encode(['error' => 'Kein Suchbegriff angegeben.']);
			return;
		}

		$lang_map = [
			'de' => ['hl' => 'de', 'gl' => 'DE', 'ceid' => 'DE:de'],
			'en' => ['hl' => 'en-US', 'gl' => 'US', 'ceid' => 'US:en'],
			'fr' => ['hl' => 'fr', 'gl' => 'FR', 'ceid' => 'FR:fr'],
			'es' => ['hl' => 'es', 'gl' => 'ES', 'ceid' => 'ES:es'],
			'it' => ['hl' => 'it', 'gl' => 'IT', 'ceid' => 'IT:it'],
		];

		$l = $lang_map[$lang] ?? $lang_map['de'];

		$encoded_query = urlencode($query);
		$feed_url = "https://news.google.com/rss/search?q={$encoded_query}&hl={$l['hl']}&gl={$l['gl']}&ceid={$l['ceid']}";

		$rc = Feeds::_subscribe($feed_url);

		switch ($rc['code']) {
			case 0:
				print json_encode(['message' => "Bereits abonniert: {$query}"]);
				break;
			case 1:
				print json_encode(['message' => "Erfolgreich abonniert: {$query}"]);
				break;
			case 2:
			case 3:
			case 5:
				print json_encode(['error' => "Fehler beim Abonnieren: {$query} (Code: {$rc['code']})"]);
				break;
			default:
				print json_encode(['error' => "Unerwarteter Fehler (Code: {$rc['code']})"]);
		}
	}

	function api_version() {
		return 2;
	}
}
