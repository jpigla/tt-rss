<?php
class Reading_Time extends Plugin {

	/** @var PluginHost $host */
	private $host;

	/** @var int Wörter pro Minute */
	private int $default_wpm = 200;

	function about() {
		return [1.0,
			"Zeigt die geschätzte Lesezeit für Artikel an",
			"tt-ss",
			false];
	}

	function init($host) {
		$this->host = $host;

		$host->add_hook($host::HOOK_RENDER_ARTICLE, $this);
		$host->add_hook($host::HOOK_RENDER_ARTICLE_CDM, $this);
		$host->add_hook($host::HOOK_RENDER_ARTICLE_API, $this);
		$host->add_hook($host::HOOK_PREFS_TAB, $this);
	}

	function get_css() {
		return file_get_contents(__DIR__ . "/reading_time.css");
	}

	/**
	 * Berechnet die Lesezeit in Minuten aus HTML-Inhalt.
	 * @param string $content HTML-Inhalt des Artikels
	 * @return int Geschätzte Lesezeit in Minuten (mindestens 1)
	 */
	private function calculate_reading_time(string $content): int {
		$text = strip_tags($content);
		$text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
		$text = trim($text);

		if (empty($text)) return 0;

		// str_word_count funktioniert nicht perfekt für CJK, aber ist gut genug für westliche Sprachen
		$word_count = str_word_count($text);

		// Für CJK-Zeichen: Zeichenanzahl / 2 als grobe Schätzung
		$cjk_chars = preg_match_all('/[\x{4e00}-\x{9fff}\x{3040}-\x{309f}\x{30a0}-\x{30ff}\x{ac00}-\x{d7af}]/u', $text);
		if ($cjk_chars > 0) {
			$word_count += (int)($cjk_chars / 2);
		}

		$wpm = (int) $this->host->get($this, "wpm", $this->default_wpm);
		if ($wpm < 50) $wpm = $this->default_wpm;

		$minutes = (int) ceil($word_count / $wpm);

		return max(1, $minutes);
	}

	/**
	 * Erzeugt das Lesezeit-Badge als HTML.
	 * @param int $minutes Lesezeit in Minuten
	 * @return string HTML-Badge
	 */
	private function render_badge(int $minutes): string {
		if ($minutes <= 0) return "";

		if ($minutes == 1) {
			$label = "1 Min. Lesezeit";
		} else {
			$label = "$minutes Min. Lesezeit";
		}

		return "<span class=\"reading-time-badge\" title=\"Geschätzte Lesezeit\">
			<i class=\"material-icons\">schedule</i> $label</span>";
	}

	/**
	 * @param array<string, mixed> $article
	 * @return array<string, mixed>
	 */
	private function inject_reading_time(array $article): array {
		$content = $article["content"] ?? "";
		$minutes = $this->calculate_reading_time($content);

		if ($minutes > 0) {
			$badge = $this->render_badge($minutes);
			$article["content"] = "<div class=\"reading-time-container\">$badge</div>" . $content;
		}

		return $article;
	}

	function hook_render_article($article) {
		return $this->inject_reading_time($article);
	}

	function hook_render_article_cdm($article) {
		return $this->inject_reading_time($article);
	}

	function hook_render_article_api($row) {
		$article = $row['headline'] ?? $row['article'];
		return $this->inject_reading_time($article);
	}

	function hook_prefs_tab($args) {
		if ($args != "prefPrefs") return;

		$wpm = $this->host->get($this, "wpm", $this->default_wpm);

		?>
		<div dojoType="dijit.layout.AccordionPane"
			title="<i class='material-icons'>schedule</i> <?= __('Lesezeit (Reading Time)') ?>">

			<form dojoType="dijit.form.Form">

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

				<fieldset>
					<label><?= __("Wörter pro Minute (WPM):") ?></label>
					<input dojoType="dijit.form.NumberSpinner"
						required="1"
						constraints="{min:50,max:1000}"
						name="wpm" value="<?= htmlspecialchars($wpm) ?>">
				</fieldset>

				<hr/>

				<?= \Controls\submit_tag(__("Speichern")) ?>
			</form>
		</div>
		<?php
	}

	function save(): void {
		$wpm = (int) clean($_POST["wpm"] ?? "200");
		if ($wpm < 50) $wpm = $this->default_wpm;

		$this->host->set($this, "wpm", $wpm);

		echo __("Einstellungen gespeichert.");
	}

	function api_version() {
		return 2;
	}
}
