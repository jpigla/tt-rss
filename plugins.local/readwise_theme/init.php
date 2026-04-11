<?php
class Readwise_Theme extends Plugin {

	/** @var PluginHost $host */
	private $host;

	function about() {
		return [1.0,
			"Readwise Reader-inspiriertes Design-System mit dunklem Theme, warmen Akzenten und optimierter Lesetypographie",
			"tt-ss",
			false];
	}

	function init($host) {
		$this->host = $host;

		$host->add_hook($host::HOOK_PREFS_TAB, $this);
	}

	function get_css() {
		$enabled = $this->host->get($this, "enabled", "1");
		if ($enabled !== "1") return "";

		$css = file_get_contents(__DIR__ . "/readwise_theme.css");

		$font_size = (int) $this->host->get($this, "font_size", 16);
		$line_height = $this->host->get($this, "line_height", "1.7");
		$content_width = (int) $this->host->get($this, "content_width", 720);
		$sidebar_width = $this->host->get($this, "sidebar_width", "280px");

		$css .= "\n:root {
			--rw-font-size-content: {$font_size}px;
			--rw-line-height: {$line_height};
			--rw-content-max-width: {$content_width}px;
			--rw-sidebar-width: {$sidebar_width};
		}\n";

		return $css;
	}

	function get_js() {
		return "";
	}

	function hook_prefs_tab($args) {
		if ($args != "prefPrefs") return;

		$enabled = $this->host->get($this, "enabled", "1");
		$font_size = $this->host->get($this, "font_size", "16");
		$line_height = $this->host->get($this, "line_height", "1.7");
		$content_width = $this->host->get($this, "content_width", "720");
		$sidebar_width = $this->host->get($this, "sidebar_width", "280px");

		$checked = $enabled === "1" ? "checked" : "";

		?>
		<div dojoType="dijit.layout.AccordionPane"
			title="<i class='material-icons'>palette</i> <?= __('Readwise Theme') ?>">

			<form dojoType="dijit.form.Form">

				<?= \Controls\pluginhandler_tags($this, "save") ?>

				<script type="dojo/method" event="onSubmit" args="evt">
					evt.preventDefault();
					if (this.validate()) {
						Notify.progress('Einstellungen werden gespeichert...', true);
						xhr.post("backend.php", this.getValues(), (reply) => {
							Notify.info(reply);
						})
					}
				</script>

				<fieldset>
					<label class="checkbox">
						<input dojoType="dijit.form.CheckBox"
							type="checkbox" name="enabled" <?= $checked ?>>
						<?= __("Theme aktivieren") ?>
					</label>
				</fieldset>

				<fieldset>
					<label><?= __("Schriftgröße (Artikelinhalt):") ?></label>
					<input dojoType="dijit.form.NumberSpinner"
						required="1"
						constraints="{min:12,max:24}"
						style="width: 80px"
						name="font_size" value="<?= htmlspecialchars($font_size) ?>"> px
				</fieldset>

				<fieldset>
					<label><?= __("Zeilenhöhe:") ?></label>
					<select dojoType="dijit.form.Select" name="line_height">
						<?php
						$options = ["1.4" => "Kompakt (1.4)", "1.5" => "Normal (1.5)", "1.6" => "Komfortabel (1.6)", "1.7" => "Großzügig (1.7)", "1.8" => "Weit (1.8)", "2.0" => "Sehr weit (2.0)"];
						foreach ($options as $val => $label) {
							$sel = ($line_height == $val) ? "selected" : "";
							echo "<option value=\"$val\" $sel>$label</option>";
						}
						?>
					</select>
				</fieldset>

				<fieldset>
					<label><?= __("Maximale Inhaltsbreite:") ?></label>
					<input dojoType="dijit.form.NumberSpinner"
						required="1"
						constraints="{min:400,max:1200}"
						style="width: 100px"
						name="content_width" value="<?= htmlspecialchars($content_width) ?>"> px
				</fieldset>

				<fieldset>
					<label><?= __("Sidebar-Breite:") ?></label>
					<select dojoType="dijit.form.Select" name="sidebar_width">
						<?php
						$widths = ["240px" => "Schmal (240px)", "280px" => "Standard (280px)", "320px" => "Breit (320px)", "360px" => "Sehr breit (360px)"];
						foreach ($widths as $val => $label) {
							$sel = ($sidebar_width == $val) ? "selected" : "";
							echo "<option value=\"$val\" $sel>$label</option>";
						}
						?>
					</select>
				</fieldset>

				<hr/>

				<p class="text-muted">
					<?= __("Readwise Reader-inspiriertes Design. Änderungen werden nach dem Neuladen wirksam.") ?>
				</p>

				<?= \Controls\submit_tag(__("Speichern")) ?>
			</form>
		</div>
		<?php
	}

	function save(): void {
		$enabled = clean($_POST["enabled"] ?? "") === "on" ? "1" : "0";
		$font_size = max(12, min(24, (int) clean($_POST["font_size"] ?? "16")));
		$line_height = clean($_POST["line_height"] ?? "1.7");
		$content_width = max(400, min(1200, (int) clean($_POST["content_width"] ?? "720")));
		$sidebar_width = clean($_POST["sidebar_width"] ?? "280px");

		$valid_line_heights = ["1.4", "1.5", "1.6", "1.7", "1.8", "2.0"];
		if (!in_array($line_height, $valid_line_heights)) $line_height = "1.7";

		$valid_widths = ["240px", "280px", "320px", "360px"];
		if (!in_array($sidebar_width, $valid_widths)) $sidebar_width = "280px";

		$this->host->set($this, "enabled", $enabled);
		$this->host->set($this, "font_size", $font_size);
		$this->host->set($this, "line_height", $line_height);
		$this->host->set($this, "content_width", $content_width);
		$this->host->set($this, "sidebar_width", $sidebar_width);

		echo __("Theme-Einstellungen gespeichert. Seite neu laden für Änderungen.");
	}

	function api_version() {
		return 2;
	}
}
