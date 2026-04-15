<?php
class Dedup_Filter extends Plugin {

	/** @var PluginHost $host */
	private $host;

	private float $default_similarity = 0.65;
	private int $default_min_length = 20;
	private int $default_lookback_hours = 72;

	function about() {
		return [1.0,
			"Erkennt und unterdrückt doppelte Artikel anhand von Titel-Ähnlichkeit und URL",
			"tt-ss",
			false];
	}

	function init($host) {
		$this->host = $host;

		$host->add_hook($host::HOOK_ARTICLE_FILTER, $this);
		$host->add_hook($host::HOOK_PREFS_TAB, $this);
		$host->add_hook($host::HOOK_PREFS_EDIT_FEED, $this);
		$host->add_hook($host::HOOK_PREFS_SAVE_FEED, $this);
	}

	function hook_article_filter($article) {
		// pg_trgm-Extension prüfen
		$res = $this->pdo->query("select 'similarity'::regproc");
		if (!$res || !$res->fetch()) return $article;

		$enable_globally = sql_bool_to_bool($this->host->get($this, "enable_globally"));

		if (!$enable_globally &&
				!in_array($article["feed"]["id"],
					$this->host->get_array($this, "enabled_feeds"))) {
			return $article;
		}

		$similarity = (float) $this->host->get($this, "similarity", $this->default_similarity);
		if ($similarity < 0.01) return $article;

		$min_length = (int) $this->host->get($this, "min_title_length", $this->default_min_length);
		$lookback = (int) $this->host->get($this, "lookback_hours", $this->default_lookback_hours);

		$title = $article["title"];
		$link = $article["link"] ?? "";
		$owner_uid = $article["owner_uid"];
		$guid = $article["guid_hashed"];

		// Zu kurze Titel überspringen
		if (mb_strlen($title) < $min_length) {
			return $article;
		}

		// Prüfung 1: Exakte URL-Duplikate (schnell)
		if (!empty($link)) {
			$sth = $this->pdo->prepare("SELECT COUNT(*) AS cnt
				FROM ttrss_entries, ttrss_user_entries
				WHERE ref_id = id
				AND link = :link
				AND guid != :guid
				AND owner_uid = :uid
				AND date_entered >= NOW() - CAST((:hours || ' hours') AS INTERVAL)");
			$sth->execute([
				'link' => $link,
				'guid' => $guid,
				'uid' => $owner_uid,
				'hours' => $lookback
			]);

			if (($sth->fetch()['cnt'] ?? 0) > 0) {
				Debug::log("dedup_filter: URL-Duplikat erkannt für: $title", Debug::LOG_EXTENDED);
				$article["force_catchup"] = true;
				return $article;
			}
		}

		// Prüfung 2: Titel-Ähnlichkeit via pg_trgm
		$sth = $this->pdo->prepare("SELECT MAX(SIMILARITY(title, :title)) AS ms
			FROM ttrss_entries, ttrss_user_entries
			WHERE ref_id = id
			AND date_entered >= NOW() - CAST((:hours || ' hours') AS INTERVAL)
			AND guid != :guid
			AND owner_uid = :uid");
		$sth->execute([
			'title' => $title,
			'hours' => $lookback,
			'guid' => $guid,
			'uid' => $owner_uid
		]);

		$row = $sth->fetch();
		$max_sim = (float) ($row['ms'] ?? 0);

		if ($max_sim >= $similarity) {
			Debug::log("dedup_filter: Titel-Duplikat erkannt ($max_sim >= $similarity): $title", Debug::LOG_EXTENDED);
			$article["force_catchup"] = true;
		}

		return $article;
	}

	function hook_prefs_edit_feed($feed_id) {
		$enabled_feeds = $this->host->get_array($this, "enabled_feeds");
		?>
		<header><?= __("Duplikatfilter (dedup_filter)") ?></header>
		<section>
			<fieldset>
				<label class="checkbox">
					<?= \Controls\checkbox_tag("dedup_enabled", in_array($feed_id, $enabled_feeds)) ?>
					<?= __('Duplikate automatisch als gelesen markieren') ?>
				</label>
			</fieldset>
		</section>
		<?php
	}

	function hook_prefs_save_feed($feed_id) {
		$enabled_feeds = $this->host->get_array($this, "enabled_feeds");
		$enable = checkbox_to_sql_bool($_POST["dedup_enabled"] ?? "");
		$key = array_search($feed_id, $enabled_feeds);

		if ($enable) {
			if ($key === false) $enabled_feeds[] = $feed_id;
		} else {
			if ($key !== false) unset($enabled_feeds[$key]);
		}

		$this->host->set($this, "enabled_feeds", array_values($enabled_feeds));
	}

	function hook_prefs_tab($args) {
		if ($args != "prefFeeds") return;

		$similarity = $this->host->get($this, "similarity", $this->default_similarity);
		$min_length = $this->host->get($this, "min_title_length", $this->default_min_length);
		$lookback = $this->host->get($this, "lookback_hours", $this->default_lookback_hours);
		$enable_globally = sql_bool_to_bool($this->host->get($this, "enable_globally"));

		$res = $this->pdo->query("select 'similarity'::regproc");
		$has_trgm = $res && $res->fetch();

		?>
		<div dojoType="dijit.layout.AccordionPane"
			title="<i class='material-icons'>filter_alt</i> <?= __('Duplikatfilter (dedup_filter)') ?>">

			<?php if (!$has_trgm) { print_error("pg_trgm Extension nicht gefunden. Bitte installieren: CREATE EXTENSION pg_trgm;"); } ?>

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

				<?= format_notice(__("Erkennt doppelte Artikel anhand von URL und Titel-Ähnlichkeit (pg_trgm). Duplikate werden automatisch als gelesen markiert. Aktiviere pro Feed im Feed-Editor oder global.")) ?>

				<fieldset>
					<label><?= __("Mindest-Ähnlichkeit (0-1):") ?></label>
					<input dojoType="dijit.form.NumberSpinner"
						constraints="{min:0.1,max:1.0,places:2}"
						required="1"
						name="similarity" value="<?= htmlspecialchars($similarity) ?>">
				</fieldset>

				<fieldset>
					<label><?= __("Mindest-Titellänge:") ?></label>
					<input dojoType="dijit.form.NumberSpinner"
						constraints="{min:5,max:200}"
						required="1"
						name="min_title_length" value="<?= htmlspecialchars($min_length) ?>">
				</fieldset>

				<fieldset>
					<label><?= __("Rückschau-Zeitraum (Stunden):") ?></label>
					<input dojoType="dijit.form.NumberSpinner"
						constraints="{min:1,max:720}"
						required="1"
						name="lookback_hours" value="<?= htmlspecialchars($lookback) ?>">
				</fieldset>

				<fieldset>
					<label class='checkbox'>
						<?= \Controls\checkbox_tag("enable_globally", $enable_globally) ?>
						<?= __("Global für alle Feeds aktivieren") ?>
					</label>
				</fieldset>

				<hr/>
				<?= \Controls\submit_tag(__("Speichern")) ?>
			</form>

			<?php
			$enabled_feeds = $this->filter_valid_feeds($this->host->get_array($this, "enabled_feeds"));
			$this->host->set($this, "enabled_feeds", $enabled_feeds);

			if (count($enabled_feeds) > 0) { ?>
				<hr/>
				<h3><?= __("Aktiviert für (klicken zum Bearbeiten):") ?></h3>
				<ul class="panel panel-scrollable list list-unstyled">
					<?php foreach ($enabled_feeds as $f) { ?>
						<li><i class='material-icons'>rss_feed</i>
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
		$this->host->set($this, "similarity", sprintf("%.2f", (float) clean($_POST["similarity"] ?? $this->default_similarity)));
		$this->host->set($this, "min_title_length", (int) clean($_POST["min_title_length"] ?? $this->default_min_length));
		$this->host->set($this, "lookback_hours", (int) clean($_POST["lookback_hours"] ?? $this->default_lookback_hours));
		$this->host->set($this, "enable_globally", checkbox_to_sql_bool(clean($_POST["enable_globally"] ?? "")));
		echo __("Einstellungen gespeichert.");
	}

	/**
	 * @param array<int> $feeds
	 * @return array<int>
	 */
	private function filter_valid_feeds(array $feeds): array {
		$valid = [];
		foreach ($feeds as $feed) {
			$sth = $this->pdo->prepare("SELECT id FROM ttrss_feeds WHERE id = ? AND owner_uid = ?");
			$sth->execute([$feed, $_SESSION['uid']]);
			if ($sth->fetch()) $valid[] = $feed;
		}
		return $valid;
	}

	function api_version() {
		return 2;
	}
}
