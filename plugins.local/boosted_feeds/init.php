<?php
class Boosted_Feeds extends Plugin {

	/** @var PluginHost $host */
	private $host;

	/** @var int Boost-Intervall in Minuten (Standard: 5 Min.) */
	private int $default_boost_interval = 5;

	function about() {
		return [1.0,
			"Kürzere Aktualisierungsintervalle für ausgewählte Feeds",
			"tt-ss",
			false];
	}

	function init($host) {
		$this->host = $host;

		$host->add_hook($host::HOOK_PREFS_TAB, $this);
		$host->add_hook($host::HOOK_PREFS_EDIT_FEED, $this);
		$host->add_hook($host::HOOK_PREFS_SAVE_FEED, $this);
	}

	function get_css() {
		return file_get_contents(__DIR__ . "/boosted_feeds.css");
	}

	/**
	 * Gibt alle geboosteten Feed-IDs zurück.
	 * @return array<int>
	 */
	private function get_boosted_feeds(): array {
		return $this->host->get_array($this, "boosted_feeds");
	}

	/**
	 * Entfernt Feeds die nicht mehr existieren aus der Liste.
	 * @param array<int> $feeds
	 * @return array<int>
	 */
	private function filter_valid_feeds(array $feeds): array {
		$valid = [];
		foreach ($feeds as $feed_id) {
			$sth = $this->pdo->prepare("SELECT id FROM ttrss_feeds WHERE id = ? AND owner_uid = ?");
			$sth->execute([$feed_id, $_SESSION['uid']]);
			if ($sth->fetch()) {
				$valid[] = $feed_id;
			}
		}
		return $valid;
	}

	/**
	 * Setzt das Update-Intervall eines Feeds auf den Boost-Wert.
	 * @param int $feed_id
	 * @param int $interval Intervall in Minuten
	 */
	private function set_feed_interval(int $feed_id, int $interval): void {
		$sth = $this->pdo->prepare("UPDATE ttrss_feeds SET update_interval = ? WHERE id = ? AND owner_uid = ?");
		$sth->execute([$interval, $feed_id, $_SESSION['uid']]);
	}

	function hook_prefs_edit_feed($feed_id) {
		$boosted_feeds = $this->get_boosted_feeds();
		$is_boosted = in_array($feed_id, $boosted_feeds);
		$interval = (int) $this->host->get($this, "boost_interval", $this->default_boost_interval);
		?>
		<header><?= __("Boosted Feed") ?></header>
		<section>
			<fieldset>
				<label class="checkbox">
					<?= \Controls\checkbox_tag("boosted_feed_enabled", $is_boosted) ?>
					<?= sprintf(__('Feed boosten (alle %d Min. aktualisieren)'), $interval) ?>
				</label>
			</fieldset>
		</section>
		<?php
	}

	function hook_prefs_save_feed($feed_id) {
		$boosted_feeds = $this->get_boosted_feeds();
		$enable = checkbox_to_sql_bool($_POST["boosted_feed_enabled"] ?? "");
		$key = array_search($feed_id, $boosted_feeds);
		$interval = (int) $this->host->get($this, "boost_interval", $this->default_boost_interval);

		if ($enable) {
			if ($key === false) {
				$boosted_feeds[] = $feed_id;
			}
			$this->set_feed_interval($feed_id, $interval);
		} else {
			if ($key !== false) {
				unset($boosted_feeds[$key]);
				// Zurück auf Standard-Intervall (0 = Systemstandard)
				$this->set_feed_interval($feed_id, 0);
			}
		}

		$this->host->set($this, "boosted_feeds", array_values($boosted_feeds));
	}

	function hook_prefs_tab($args) {
		if ($args != "prefFeeds") return;

		$interval = (int) $this->host->get($this, "boost_interval", $this->default_boost_interval);
		$boosted_feeds = $this->filter_valid_feeds($this->get_boosted_feeds());
		$this->host->set($this, "boosted_feeds", $boosted_feeds);

		?>
		<div dojoType="dijit.layout.AccordionPane"
			title="<i class='material-icons'>bolt</i> <?= __('Boosted Feeds') ?>">

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

				<?= format_notice(__("Geboostete Feeds werden häufiger aktualisiert. Aktiviere den Boost für einzelne Feeds im Feed-Editor.")) ?>

				<fieldset>
					<label><?= __("Boost-Intervall (Minuten):") ?></label>
					<input dojoType="dijit.form.NumberSpinner"
						required="1"
						constraints="{min:1,max:60}"
						name="boost_interval" value="<?= htmlspecialchars($interval) ?>">
				</fieldset>

				<hr/>

				<?= \Controls\submit_tag(__("Speichern")) ?>
			</form>

			<?php if (count($boosted_feeds) > 0) { ?>
				<hr/>
				<h3><?= __("Aktuell geboostete Feeds (klicken zum Bearbeiten):") ?></h3>
				<ul class="panel panel-scrollable list list-unstyled">
					<?php foreach ($boosted_feeds as $f) { ?>
						<li class="boosted-feed-item">
							<i class='material-icons'>bolt</i>
							<a href='#' onclick="CommonDialogs.editFeed(<?= $f ?>)">
								<?= Feeds::_get_title($f, $_SESSION['uid']) ?>
							</a>
						</li>
					<?php } ?>
				</ul>
			<?php } ?>
		</div>
		<?php
	}

	function save(): void {
		$interval = (int) clean($_POST["boost_interval"] ?? "5");
		if ($interval < 1) $interval = $this->default_boost_interval;

		$old_interval = (int) $this->host->get($this, "boost_interval", $this->default_boost_interval);
		$this->host->set($this, "boost_interval", $interval);

		// Alle geboosteten Feeds auf neues Intervall aktualisieren
		if ($old_interval != $interval) {
			$boosted_feeds = $this->get_boosted_feeds();
			foreach ($boosted_feeds as $feed_id) {
				$this->set_feed_interval($feed_id, $interval);
			}
		}

		echo __("Einstellungen gespeichert.");
	}

	function api_version() {
		return 2;
	}
}
