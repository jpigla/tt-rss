<?php
class Keyword_Spotlight extends Plugin {

	/** @var PluginHost $host */
	private $host;

	function about() {
		return [1.0,
			"Hebt definierte Keywords in Artikeln farblich hervor",
			"tt-ss",
			false];
	}

	function init($host) {
		$this->host = $host;

		$host->add_hook($host::HOOK_RENDER_ARTICLE, $this);
		$host->add_hook($host::HOOK_RENDER_ARTICLE_CDM, $this);
		$host->add_hook($host::HOOK_PREFS_TAB, $this);
	}

	function get_css() {
		return file_get_contents(__DIR__ . "/keyword_spotlight.css");
	}

	/**
	 * Gibt die konfigurierten Keyword-Gruppen zurück.
	 * Format: [["keywords" => "foo,bar", "color" => "#ff0"], ...]
	 * @return array<int, array{keywords: string, color: string}>
	 */
	private function get_keyword_groups(): array {
		$groups = $this->host->get($this, "keyword_groups");
		if (!is_array($groups)) return [];
		return $groups;
	}

	/**
	 * Parsed alle Keywords aus den Gruppen in ein flaches Array: keyword => color.
	 * @return array<string, string>
	 */
	private function get_keyword_color_map(): array {
		$map = [];
		foreach ($this->get_keyword_groups() as $group) {
			$color = $group['color'] ?? '#fff3cd';
			$keywords = array_map('trim', explode(',', $group['keywords'] ?? ''));
			foreach ($keywords as $kw) {
				if (mb_strlen($kw) >= 2) {
					$map[mb_strtolower($kw)] = $color;
				}
			}
		}
		return $map;
	}

	/**
	 * Hebt Keywords im HTML-Content hervor.
	 * @param string $content HTML-Inhalt
	 * @return string Modifizierter HTML-Inhalt
	 */
	private function highlight_keywords(string $content): string {
		$kw_map = $this->get_keyword_color_map();
		if (empty($kw_map)) return $content;

		$doc = new DOMDocument();
		// Suppress HTML5-Warnungen
		$prev = libxml_use_internal_errors(true);
		$doc->loadHTML('<?xml encoding="utf-8" ?><div>' . $content . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
		libxml_use_internal_errors($prev);

		$xpath = new DOMXPath($doc);

		// Alle Textknoten finden (nicht in script/style)
		$text_nodes = $xpath->query('//text()[not(ancestor::script) and not(ancestor::style) and not(ancestor::mark)]');

		if ($text_nodes === false) return $content;

		// Regex-Pattern aus allen Keywords bauen
		$escaped_keywords = [];
		foreach (array_keys($kw_map) as $kw) {
			$escaped_keywords[] = preg_quote($kw, '/');
		}
		$pattern = '/(' . implode('|', $escaped_keywords) . ')/iu';

		$nodes_to_process = [];
		foreach ($text_nodes as $node) {
			$nodes_to_process[] = $node;
		}

		foreach ($nodes_to_process as $text_node) {
			$text = $text_node->nodeValue;
			if (!preg_match($pattern, $text)) continue;

			$parts = preg_split($pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE);
			if ($parts === false || count($parts) <= 1) continue;

			$parent = $text_node->parentNode;
			if ($parent === null) continue;

			$frag = $doc->createDocumentFragment();
			foreach ($parts as $part) {
				$part_lower = mb_strtolower($part);
				if (isset($kw_map[$part_lower])) {
					$mark = $doc->createElement('mark');
					$mark->setAttribute('class', 'ks-highlight');
					$mark->setAttribute('style', 'background-color: ' . htmlspecialchars($kw_map[$part_lower]));
					$mark->appendChild($doc->createTextNode($part));
					$frag->appendChild($mark);
				} else {
					$frag->appendChild($doc->createTextNode($part));
				}
			}

			$parent->replaceChild($frag, $text_node);
		}

		// Inneres HTML extrahieren (ohne das wrapper-div)
		$wrapper = $doc->getElementsByTagName('div')->item(0);
		if ($wrapper === null) return $content;

		$result = '';
		foreach ($wrapper->childNodes as $child) {
			$result .= $doc->saveHTML($child);
		}

		return $result;
	}

	/**
	 * @param array<string, mixed> $article
	 * @return array<string, mixed>
	 */
	private function process_article(array $article): array {
		$content = $article["content"] ?? "";
		if (!empty($content)) {
			$article["content"] = $this->highlight_keywords($content);
		}
		return $article;
	}

	function hook_render_article($article) {
		return $this->process_article($article);
	}

	function hook_render_article_cdm($article) {
		return $this->process_article($article);
	}

	function hook_prefs_tab($args) {
		if ($args != "prefPrefs") return;

		$groups = $this->get_keyword_groups();
		?>
		<div dojoType="dijit.layout.AccordionPane"
			title="<i class='material-icons'>highlight</i> <?= __('Keyword-Spotlights') ?>">

			<form dojoType="dijit.form.Form" id="keyword_spotlight_form">

				<?= \Controls\pluginhandler_tags($this, "save") ?>

				<script type="dojo/method" event="onSubmit" args="evt">
					evt.preventDefault();
					if (this.validate()) {
						Notify.progress('Saving data...', true);
						xhr.post("backend.php", this.getValues(), (reply) => {
							Notify.info(reply);
						})
					}
				</script>

				<?= format_notice(__("Definiere Keywords (kommagetrennt) mit zugehöriger Highlight-Farbe. Keywords mit weniger als 2 Zeichen werden ignoriert.")) ?>

				<div id="ks-groups-container">
					<?php
					if (empty($groups)) {
						$groups = [["keywords" => "", "color" => "#fff3cd"]];
					}
					foreach ($groups as $i => $group) {
						$keywords = htmlspecialchars($group['keywords'] ?? '');
						$color = htmlspecialchars($group['color'] ?? '#fff3cd');
						?>
						<fieldset class="ks-group">
							<label><?= sprintf(__("Gruppe %d - Keywords:"), $i + 1) ?></label>
							<input type="text" dojoType="dijit.form.TextBox"
								name="ks_keywords[]"
								style="width: 400px"
								placeholder="<?= __('keyword1, keyword2, keyword3') ?>"
								value="<?= $keywords ?>">
							<label><?= __("Farbe:") ?></label>
							<input type="color"
								name="ks_colors[]"
								value="<?= $color ?>"
								style="width: 40px; height: 28px; padding: 0; border: 1px solid var(--border-color, #ccc); cursor: pointer;">
						</fieldset>
						<?php
					}

					// Leere Gruppe als Template für neue
					for ($i = count($groups); $i < 5; $i++) {
						?>
						<fieldset class="ks-group">
							<label><?= sprintf(__("Gruppe %d - Keywords:"), $i + 1) ?></label>
							<input type="text" dojoType="dijit.form.TextBox"
								name="ks_keywords[]"
								style="width: 400px"
								placeholder="<?= __('keyword1, keyword2, keyword3') ?>"
								value="">
							<label><?= __("Farbe:") ?></label>
							<input type="color"
								name="ks_colors[]"
								value="<?= htmlspecialchars($this->default_colors()[$i] ?? '#fff3cd') ?>"
								style="width: 40px; height: 28px; padding: 0; border: 1px solid var(--border-color, #ccc); cursor: pointer;">
						</fieldset>
						<?php
					}
					?>
				</div>

				<hr/>

				<?= \Controls\submit_tag(__("Speichern")) ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Standardfarben für Gruppen.
	 * @return array<int, string>
	 */
	private function default_colors(): array {
		return ['#fff3cd', '#cce5ff', '#d4edda', '#f8d7da', '#e2d5f1'];
	}

	function save(): void {
		$keywords_arr = $_POST["ks_keywords"] ?? [];
		$colors_arr = $_POST["ks_colors"] ?? [];

		$groups = [];
		for ($i = 0; $i < count($keywords_arr); $i++) {
			$kw = trim($keywords_arr[$i] ?? '');
			$color = trim($colors_arr[$i] ?? '#fff3cd');

			if (!empty($kw)) {
				// Keywords normalisieren
				$kw = implode(', ', array_filter(
					array_map('trim', explode(',', $kw)),
					fn($k) => mb_strlen($k) >= 2
				));
				if (!empty($kw)) {
					$groups[] = ['keywords' => $kw, 'color' => $color];
				}
			}
		}

		$this->host->set($this, "keyword_groups", $groups);

		echo __("Einstellungen gespeichert.");
	}

	function api_version() {
		return 2;
	}
}
