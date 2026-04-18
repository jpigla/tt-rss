<?php
class Compact_Images extends Plugin {

	/** @var PluginHost $host */
	private $host;

	function about() {
		return [1.0,
			"Kompakte Bilddarstellung und Original-URL unter dem Artikeltitel",
			"tt-ss",
			false];
	}

	function init($host) {
		$this->host = $host;

		$host->add_hook($host::HOOK_RENDER_ARTICLE_CDM, $this);
		$host->add_hook($host::HOOK_RENDER_ARTICLE, $this);
		$host->add_hook($host::HOOK_PREFS_TAB, $this);
	}

	function get_css() {
		$enabled = $this->host->get($this, "enabled", "1");
		if ($enabled !== "1") return "";

		$thumb_size = (int) $this->host->get($this, "thumb_size", 120);

		return "
		.ci-thumb-wrap {
			float: left;
			width: {$thumb_size}px;
			height: {$thumb_size}px;
			margin: 0 16px 8px 0;
			border-radius: 8px;
			overflow: hidden;
			flex-shrink: 0;
		}

		.ci-thumb-wrap img {
			width: 100% !important;
			height: 100% !important;
			max-width: none !important;
			object-fit: cover;
			display: block;
			border-radius: 8px;
		}

		.content-inner::after,
		.post .content::after {
			content: '';
			display: table;
			clear: both;
		}

		.ci-article-url {
			font-size: 12px;
			color: #999;
			font-weight: normal;
			word-break: break-all;
			display: inline;
			vertical-align: middle;
			text-decoration: none;
		}

		.ci-article-url:hover {
			color: #666;
			text-decoration: underline;
		}

		.ci-article-url .ci-favicon {
			width: 16px;
			height: 16px;
			vertical-align: text-bottom;
			margin-right: 4px;
			display: inline-block;
		}

		.cdm .header .ci-article-url {
			margin-left: 4px;
		}

		.post .header .ci-url-row {
			padding: 0 4px 4px;
		}

		.post .header .ci-url-row .ci-article-url .ci-favicon {
			margin-left: 0;
		}
		";
	}

	function get_js() {
		$enabled = $this->host->get($this, "enabled", "1");
		if ($enabled !== "1") return "";

		$show_url = $this->host->get($this, "show_url", "1");
		$js = "";

		// Konfiguration fuer JS bereitstellen
		$js .= "var CI_CONFIG = { showUrl: " . ($show_url === "1" ? "true" : "false") . " };\n";
		$js .= file_get_contents(__DIR__ . "/compact_images.js");

		return $js;
	}

	/**
	 * Findet das erste Bild-Enclosure und gibt dessen URL zurueck.
	 */
	private function find_enclosure_image(array $article): string {
		$enclosures = $article['enclosures'] ?? [];
		$entries = $enclosures['entries'] ?? [];

		foreach ($entries as $enc) {
			$type = $enc['content_type'] ?? '';
			$url = $enc['content_url'] ?? '';
			if (str_contains($type, 'image/') && !empty($url)) {
				return $url;
			}
		}

		return '';
	}

	/**
	 * Erzeugt ein ci-thumb-wrap HTML-Fragment fuer eine Bild-URL.
	 */
	private function make_thumb_html(string $url): string {
		$escaped = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
		return '<div class="ci-thumb-wrap"><img loading="lazy" src="' . $escaped . '"/></div>';
	}

	private function compact_first_image(array $article): array {
		$enabled = $this->host->get($this, "enabled", "1");
		if ($enabled !== "1") return $article;

		$content = $article['content'] ?? '';
		if (empty($content)) return $article;

		$min_width = (int) $this->host->get($this, "min_width", 200);

		$doc = new DOMDocument();
		@$doc->loadHTML('<?xml encoding="UTF-8">' . '<div id="ci-root">' . $content . '</div>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR);

		$images = $doc->getElementsByTagName('img');

		// Erstes grosses Bild im Content suchen
		$target_img = null;
		if ($images->length > 0) {
			for ($i = 0; $i < $images->length; $i++) {
				$img = $images->item($i);
				$width = (int) $img->getAttribute('width');
				$src = $img->getAttribute('src');

				if (str_starts_with($src, 'data:')) continue;
				if ($width > 0 && $width < $min_width) continue;

				$target_img = $img;
				break;
			}
		}

		// Kein Content-Bild gefunden? Enclosure-Bild als Fallback nutzen
		if (!$target_img) {
			$enc_url = $this->find_enclosure_image($article);
			if (!empty($enc_url)) {
				// Enclosure-Thumbnail am Anfang des Contents einfuegen
				$article['content'] = $this->make_thumb_html($enc_url) . $content;
			}
			return $article;
		}

		// Content-Bild als Thumbnail wrappen
		$wrapper = $doc->createElement('div');
		$wrapper->setAttribute('class', 'ci-thumb-wrap');

		$parent = $target_img->parentNode;

		$replace_node = $target_img;
		if ($parent !== null && $parent->nodeName !== 'div') {
			$parent_id = $parent->getAttribute('id');
			if ($parent_id !== 'ci-root') {
				$has_other_content = false;
				foreach ($parent->childNodes as $child) {
					if ($child === $target_img) continue;
					if ($child->nodeType === XML_TEXT_NODE && trim($child->textContent) === '') continue;
					$has_other_content = true;
					break;
				}
				if (!$has_other_content) {
					$replace_node = $parent;
				}
			}
		}

		$cloned_img = $target_img->cloneNode(true);
		$cloned_img->removeAttribute('width');
		$cloned_img->removeAttribute('height');

		$wrapper->appendChild($cloned_img);

		$replace_node->parentNode->insertBefore($wrapper, $replace_node);
		$replace_node->parentNode->removeChild($replace_node);

		$root = $doc->getElementById('ci-root');
		$html = '';
		foreach ($root->childNodes as $child) {
			$html .= $doc->saveHTML($child);
		}

		$article['content'] = $html;
		return $article;
	}

	function hook_render_article_cdm($article) {
		return $this->compact_first_image($article);
	}

	function hook_render_article($article) {
		return $this->compact_first_image($article);
	}

	function hook_prefs_tab($args) {
		if ($args != "prefPrefs") return;

		$enabled = $this->host->get($this, "enabled", "1");
		$thumb_size = $this->host->get($this, "thumb_size", "120");
		$min_width = $this->host->get($this, "min_width", "200");
		$show_url = $this->host->get($this, "show_url", "1");

		$checked = ($enabled === "1") ? "checked" : "";
		$show_url_checked = ($show_url === "1") ? "checked" : "";

		print '<div dojoType="dijit.layout.AccordionPane"
			title="<i class=\'material-icons\'>photo_size_select_large</i> Kompakte Bilder">';

		print '<form dojoType="dijit.form.Form">';

		print \Controls\pluginhandler_tags($this, "save");

		print '<script type="dojo/method" event="onSubmit" args="evt">
			evt.preventDefault();
			if (this.validate()) {
				Notify.progress("Einstellungen werden gespeichert...", true);
				xhr.post("backend.php", this.getValues(), (reply) => {
					Notify.info(reply);
				})
			}
		</script>';

		print "<fieldset>
			<label class='checkbox'>
				<input dojoType='dijit.form.CheckBox'
					type='checkbox' name='enabled' $checked>
				Kompakte Bilder aktivieren
			</label>
		</fieldset>";

		print "<fieldset>
			<label class='checkbox'>
				<input dojoType='dijit.form.CheckBox'
					type='checkbox' name='show_url' $show_url_checked>
				Original-URL unter dem Artikeltitel anzeigen
			</label>
		</fieldset>";

		print '<fieldset>
			<label>Thumbnail-Groesse:</label>
			<select dojoType="dijit.form.Select" name="thumb_size">';

		$sizes = ["80" => "Klein (80px)", "100" => "Mittel (100px)", "120" => "Standard (120px)", "150" => "Gross (150px)", "180" => "Sehr gross (180px)"];
		foreach ($sizes as $val => $label) {
			$sel = ($thumb_size == $val) ? "selected" : "";
			print "<option value=\"$val\" $sel>$label</option>";
		}

		print '</select>
		</fieldset>';

		print '<fieldset>
			<label>Mindestbreite fuer Kompaktierung:</label>
			<input dojoType="dijit.form.NumberSpinner"
				required="1"
				constraints="{min:100,max:600}"
				style="width: 100px"
				name="min_width" value="' . htmlspecialchars($min_width) . '"> px
			<span class="text-muted">Bilder kleiner als dieser Wert werden nicht verkleinert</span>
		</fieldset>';

		print '<hr/>';

		print '<p class="text-muted">
			Wandelt das erste grosse Bild in ein kompaktes Thumbnail um, das links neben dem Text schwebt.
			Kleine Bilder (Icons, Logos) bleiben unveraendert.
		</p>';

		print \Controls\submit_tag("Speichern");

		print '</form>';
		print '</div>';
	}

	function save(): void {
		$enabled = (clean($_POST["enabled"] ?? "") === "on") ? "1" : "0";
		$show_url = (clean($_POST["show_url"] ?? "") === "on") ? "1" : "0";
		$thumb_size = clean($_POST["thumb_size"] ?? "120");

		$raw_min = (int) clean($_POST["min_width"] ?? "200");
		$min_width = max(100, min(600, $raw_min));

		$valid_sizes = ["80", "100", "120", "150", "180"];
		if (!in_array($thumb_size, $valid_sizes)) {
			$thumb_size = "120";
		}

		$this->host->set($this, "enabled", $enabled);
		$this->host->set($this, "show_url", $show_url);
		$this->host->set($this, "thumb_size", $thumb_size);
		$this->host->set($this, "min_width", $min_width);

		echo "Einstellungen gespeichert. Seite neu laden.";
	}

	function api_version() {
		return 2;
	}
}
