<?php
class Save_Page extends Plugin {

	/** @var PluginHost $host */
	private $host;

	function about() {
		return [1.1,
			"Beliebige Webseiten per URL als Artikel speichern (erscheint in Gespeicherte Artikel)",
			"admin",
			false];
	}

	function init($host) {
		$this->host = $host;

		$host->add_hook($host::HOOK_TOOLBAR_BUTTON, $this);
		$host->add_hook($host::HOOK_PREFS_TAB, $this);
	}

	function get_js() {
		return <<<'JS'
Plugins.SavePage = {
	showDialog: function() {
		const dialog = new fox.SingleUseDialog({
			title: 'Seite speichern',
			execute: function() {
				const data = this.attr('value');

				if (!data.url) {
					Notify.error('Bitte eine URL eingeben.');
					return;
				}

				Notify.progress('Seite wird gespeichert...');

				xhr.json("backend.php", {
					op: "pluginhandler",
					plugin: "save_page",
					method: "save",
					url: data.url,
					title: data.title || ''
				}, (reply) => {
					if (reply.error) {
						Notify.error(reply.error);
					} else {
						Notify.info(reply.message || 'Seite gespeichert.');
					}
					dialog.hide();
				});
			},
			content: `
				<section>
					<fieldset>
						<label>URL:</label>
						<input dojoType="dijit.form.ValidationTextBox"
							required="true"
							name="url"
							style="width: 350px"
							placeholder="https://example.com/artikel">
					</fieldset>
					<fieldset>
						<label>Titel (optional):</label>
						<input dojoType="dijit.form.TextBox"
							name="title"
							style="width: 350px"
							placeholder="Wird automatisch aus der Seite ermittelt">
					</fieldset>
				</section>
				<footer class="text-center">
					<button dojoType="dijit.form.Button" class="alt-primary" type="submit">Speichern</button>
					<button dojoType="dijit.form.Button" onclick="App.dialogOf(this).hide()">Abbrechen</button>
				</footer>
			`
		});

		dialog.show();
	}
};
JS;
	}

	function hook_toolbar_button() {
		return "<i class='material-icons'
			style='cursor: pointer'
			onclick=\"Plugins.SavePage.showDialog()\"
			title=\"URL speichern\">add_link</i>";
	}

	function save() : void {
		$url = clean($_REQUEST['url'] ?? '');
		$title = clean($_REQUEST['title'] ?? '');

		if (empty($url)) {
			print json_encode(['error' => 'Keine URL angegeben.']);
			return;
		}

		// URL validieren
		if (!preg_match('#^https?://#i', $url)) {
			$url = 'https://' . $url;
		}

		// SSRF-Schutz: Interne/private Netzwerke blockieren
		$host = parse_url($url, PHP_URL_HOST);
		if (!$host || preg_match('/^(localhost|127\.|10\.|172\.(1[6-9]|2\d|3[01])\.|192\.168\.|0\.|\[::1\]|\[fd|169\.254\.)/i', $host)) {
			print json_encode(['error' => 'Interne Adressen sind nicht erlaubt.']);
			return;
		}

		$html = UrlHelper::fetch(['url' => $url, 'timeout' => 15]);

		if (!$html) {
			print json_encode(['error' => 'Seite konnte nicht abgerufen werden.']);
			return;
		}

		// Titel aus der Seite extrahieren, falls nicht angegeben
		if (empty($title)) {
			$title = $this->extract_title($html);
		}

		if (empty($title)) {
			$title = $url;
		}

		// Inhalt extrahieren
		$content = $this->extract_content($html);

		if (empty($content)) {
			$content = '<p><a href="' . htmlspecialchars($url) . '">' . htmlspecialchars($url) . '</a></p>';
		}

		$result = Article::_create_published_article($title, $url, $content, '', $_SESSION['uid']);

		if ($result) {
			// Zusätzlich als markiert (gespeichert) kennzeichnen
			$pdo = Db::pdo();
			$guid = 'SHA1:' . sha1("ttshared:" . $url . $_SESSION['uid']);

			$sth = $pdo->prepare("UPDATE ttrss_user_entries SET marked = true, last_marked = NOW()
				WHERE ref_id = (SELECT id FROM ttrss_entries WHERE guid = ? LIMIT 1)
				AND owner_uid = ?");
			$sth->execute([$guid, $_SESSION['uid']]);

			print json_encode(['message' => 'Seite erfolgreich gespeichert: ' . $title]);
		} else {
			print json_encode(['error' => 'Fehler beim Speichern der Seite.']);
		}
	}

	/**
	 * Titel aus HTML extrahieren.
	 */
	private function extract_title(string $html): string {
		if (preg_match('#<title[^>]*>([^<]+)</title>#i', $html, $m)) {
			return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
		}

		// og:title als Fallback
		if (preg_match('#<meta\s+property=["\']og:title["\']\s+content=["\']([^"\']+)["\']#i', $html, $m)) {
			return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
		}

		return '';
	}

	/**
	 * Hauptinhalt aus HTML extrahieren.
	 */
	private function extract_content(string $html): string {
		libxml_use_internal_errors(true);
		$doc = new DOMDocument();
		$doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR);
		libxml_clear_errors();

		$xpath = new DOMXPath($doc);

		// Versuche <article>, <main> oder den Body zu verwenden
		$selectors = ['//article', '//main', '//div[@role="main"]', '//body'];

		foreach ($selectors as $sel) {
			$nodes = $xpath->query($sel);
			if ($nodes && $nodes->length > 0) {
				$node = $nodes->item(0);

				// Script- und Style-Elemente entfernen
				$remove = $xpath->query('.//script | .//style | .//nav | .//header | .//footer | .//aside', $node);
				if ($remove) {
					foreach ($remove as $r) {
						$r->parentNode->removeChild($r);
					}
				}

				$content = $doc->saveHTML($node);
				if (!empty(trim(strip_tags($content)))) {
					return $content;
				}
			}
		}

		return '';
	}

	function hook_prefs_tab($args) {
		if ($args != "prefPrefs") return;

		?>
		<div dojoType="dijit.layout.AccordionPane"
			title="<i class='material-icons'>save</i> <?= __('Seite speichern') ?>">

			<h3><?= __('Webseiten als Artikel speichern') ?></h3>

			<p><?= __('Dieses Plugin fügt eine Schaltfläche in der Symbolleiste hinzu, mit der beliebige Webseiten als Artikel im Veröffentlicht-Feed gespeichert werden können.') ?></p>

			<h4><?= __('Verwendung:') ?></h4>
			<ol>
				<li><?= __('Klicken Sie auf das Speichern-Symbol in der Symbolleiste.') ?></li>
				<li><?= __('Geben Sie die URL der gewünschten Webseite ein.') ?></li>
				<li><?= __('Optional: Geben Sie einen eigenen Titel ein.') ?></li>
				<li><?= __('Klicken Sie auf "Speichern". Der Artikel erscheint im Veröffentlicht-Feed.') ?></li>
			</ol>

			<p class="text-muted"><?= __('Der Titel wird automatisch aus der Webseite ermittelt, falls nicht manuell angegeben.') ?></p>
		</div>
		<?php
	}

	function api_version() {
		return 2;
	}
}
