<?php
class Output_Feeds extends Plugin {

	/** @var PluginHost $host */
	private $host;

	function about() {
		return [1.0,
			"Öffentliche RSS-Feeds aus Ordnern, Labels oder Tags generieren",
			"tt-ss",
			false];
	}

	function init($host) {
		$this->host = $host;

		$host->add_hook($host::HOOK_PREFS_TAB, $this);

		$m = new Db_Migrations();
		$m->initialize_for_plugin($this);
		$m->migrate();
	}

	function get_css() {
		return file_get_contents(__DIR__ . "/output_feeds.css");
	}

	function is_public_method($method) {
		return $method === "rss";
	}

	function csrf_ignore($method) {
		return $method === "rss";
	}

	/**
	 * Öffentlichen RSS-Feed ausgeben.
	 */
	function rss(): void {
		$key = clean($_REQUEST["key"] ?? "");

		if (empty($key)) {
			header($_SERVER["SERVER_PROTOCOL"] . " 404 Not Found");
			print "Feed nicht gefunden.";
			return;
		}

		$sth = $this->pdo->prepare("SELECT * FROM ttrss_plugin_output_feeds WHERE access_key = ?");
		$sth->execute([$key]);
		$config = $sth->fetch();

		if (!$config) {
			header($_SERVER["SERVER_PROTOCOL"] . " 404 Not Found");
			print "Feed nicht gefunden.";
			return;
		}

		$owner_uid = $config['owner_uid'];
		$max_items = (int)$config['max_items'];
		$feed_title = $config['title'];

		$articles = $this->fetch_articles($config, $owner_uid, $max_items);

		header("Content-Type: application/rss+xml; charset=utf-8");
		header("X-Content-Type-Options: nosniff");

		$self_url = htmlspecialchars(Config::get_self_url() .
			"/backend.php?op=pluginhandler&plugin=output_feeds&pmethod=rss&key=" . urlencode($key));

		echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
<channel>
	<title><?= htmlspecialchars($feed_title) ?></title>
	<link><?= htmlspecialchars(Config::get_self_url()) ?></link>
	<description><?= htmlspecialchars("TT-RSS Ausgabe-Feed: $feed_title") ?></description>
	<atom:link href="<?= $self_url ?>" rel="self" type="application/rss+xml"/>
	<lastBuildDate><?= date('r') ?></lastBuildDate>
<?php foreach ($articles as $article) { ?>
	<item>
		<title><?= htmlspecialchars($article['title'] ?? '') ?></title>
		<link><?= htmlspecialchars($article['link'] ?? '') ?></link>
		<guid isPermaLink="false"><?= htmlspecialchars($article['guid'] ?? $article['link'] ?? '') ?></guid>
		<pubDate><?= date('r', strtotime($article['updated'])) ?></pubDate>
		<description><![CDATA[<?= Sanitizer::sanitize($article['content'] ?? '', false, $owner_uid) ?>]]></description>
	</item>
<?php } ?>
</channel>
</rss>
		<?php
	}

	/**
	 * Artikel anhand der Feed-Konfiguration abfragen.
	 * @return array<int, array<string, mixed>>
	 */
	private function fetch_articles(array $config, int $owner_uid, int $max_items): array {
		$where = ["ue.owner_uid = ?"];
		$params = [$owner_uid];

		if (!empty($config['feed_id'])) {
			$where[] = "e.feed_id = ?";
			$params[] = (int)$config['feed_id'];
		}

		if (!empty($config['cat_id'])) {
			$where[] = "e.feed_id IN (SELECT id FROM ttrss_feeds WHERE cat_id = ? AND owner_uid = ?)";
			$params[] = (int)$config['cat_id'];
			$params[] = $owner_uid;
		}

		if (!empty($config['label_id'])) {
			$where[] = "ue.ref_id IN (SELECT article_id FROM ttrss_user_labels2 WHERE label_id = ?)";
			$params[] = (int)$config['label_id'];
		}

		if (!empty($config['tag'])) {
			$where[] = "ue.int_id IN (SELECT post_int_id FROM ttrss_tags WHERE tag_name = ? AND owner_uid = ?)";
			$params[] = $config['tag'];
			$params[] = $owner_uid;
		}

		$where_sql = implode(" AND ", $where);

		$sth = $this->pdo->prepare("SELECT e.title, e.link, e.guid, e.content, e.updated
			FROM ttrss_entries e
			JOIN ttrss_user_entries ue ON ue.ref_id = e.id
			WHERE $where_sql
			ORDER BY e.updated DESC
			LIMIT ?");
		$params[] = $max_items;
		$sth->execute($params);

		return $sth->fetchAll();
	}

	function hook_prefs_tab($args) {
		if ($args != "prefFeeds") return;

		$owner_uid = $_SESSION['uid'];

		// Bestehende Ausgabe-Feeds laden
		$sth = $this->pdo->prepare("SELECT * FROM ttrss_plugin_output_feeds
			WHERE owner_uid = ? ORDER BY created_at DESC");
		$sth->execute([$owner_uid]);
		$feeds = $sth->fetchAll();

		// Feeds für Auswahlmenü
		$feed_sth = $this->pdo->prepare("SELECT id, title FROM ttrss_feeds
			WHERE owner_uid = ? ORDER BY title");
		$feed_sth->execute([$owner_uid]);
		$available_feeds = $feed_sth->fetchAll();

		// Kategorien für Auswahlmenü
		$cat_sth = $this->pdo->prepare("SELECT id, title FROM ttrss_feed_categories
			WHERE owner_uid = ? ORDER BY title");
		$cat_sth->execute([$owner_uid]);
		$available_cats = $cat_sth->fetchAll();

		// Labels für Auswahlmenü
		$label_sth = $this->pdo->prepare("SELECT id, caption FROM ttrss_labels2
			WHERE owner_uid = ? ORDER BY caption");
		$label_sth->execute([$owner_uid]);
		$available_labels = $label_sth->fetchAll();

		$base_url = Config::get_self_url() . "/backend.php?op=pluginhandler&plugin=output_feeds&pmethod=rss&key=";

		?>
		<div dojoType="dijit.layout.AccordionPane"
			title="<i class='material-icons'>rss_feed</i> <?= __('Ausgabe-Feeds') ?>">

			<h3><?= __('Bestehende Ausgabe-Feeds') ?></h3>

			<?php if (empty($feeds)) { ?>
				<p class="text-muted"><?= __('Noch keine Ausgabe-Feeds erstellt.') ?></p>
			<?php } else { ?>
				<table class="prefOutputFeeds" width="100%">
					<thead>
						<tr>
							<th><?= __('Titel') ?></th>
							<th><?= __('Quelle') ?></th>
							<th><?= __('Max. Einträge') ?></th>
							<th><?= __('Feed-URL') ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($feeds as $feed) { ?>
						<tr>
							<td><?= htmlspecialchars($feed['title']) ?></td>
							<td>
								<?php
								if (!empty($feed['feed_id'])) echo __('Feed') . " #" . $feed['feed_id'];
								elseif (!empty($feed['cat_id'])) echo __('Kategorie') . " #" . $feed['cat_id'];
								elseif (!empty($feed['label_id'])) echo __('Label') . " #" . $feed['label_id'];
								elseif (!empty($feed['tag'])) echo __('Tag') . ": " . htmlspecialchars($feed['tag']);
								?>
							</td>
							<td><?= (int)$feed['max_items'] ?></td>
							<td>
								<input type="text" readonly
									style="width: 300px; font-size: 11px"
									value="<?= htmlspecialchars($base_url . $feed['access_key']) ?>"
									onclick="this.select()">
							</td>
							<td>
								<form dojoType="dijit.form.Form" style="display: inline">
									<?= \Controls\pluginhandler_tags($this, "delete_feed") ?>
									<input type="hidden" name="feed_id" value="<?= (int)$feed['id'] ?>">
									<button dojoType="dijit.form.Button" type="submit" class="alt-danger">
										<i class="material-icons">delete</i>
									</button>
								</form>
							</td>
						</tr>
						<?php } ?>
					</tbody>
				</table>
			<?php } ?>

			<hr/>

			<h3><?= __('Neuen Ausgabe-Feed erstellen') ?></h3>

			<form dojoType="dijit.form.Form">
				<?= \Controls\pluginhandler_tags($this, "create_feed") ?>

				<script type="dojo/method" event="onSubmit" args="evt">
					evt.preventDefault();
					if (this.validate()) {
						Notify.progress('Feed wird erstellt...', true);
						xhr.post("backend.php", this.getValues(), (reply) => {
							Notify.info(reply);
							window.location.reload();
						})
					}
				</script>

				<fieldset>
					<label><?= __('Titel:') ?></label>
					<input dojoType="dijit.form.ValidationTextBox"
						required="true"
						name="title"
						style="width: 300px"
						placeholder="<?= __('Mein Ausgabe-Feed') ?>">
				</fieldset>

				<fieldset>
					<label><?= __('Quelltyp:') ?></label>
					<select dojoType="dijit.form.Select" name="source_type"
						onchange="
							document.getElementById('of_feed_select').style.display = this.value === 'feed' ? '' : 'none';
							document.getElementById('of_cat_select').style.display = this.value === 'cat' ? '' : 'none';
							document.getElementById('of_label_select').style.display = this.value === 'label' ? '' : 'none';
							document.getElementById('of_tag_input').style.display = this.value === 'tag' ? '' : 'none';
						">
						<option value="feed"><?= __('Feed') ?></option>
						<option value="cat"><?= __('Kategorie') ?></option>
						<option value="label"><?= __('Label') ?></option>
						<option value="tag"><?= __('Tag') ?></option>
					</select>
				</fieldset>

				<fieldset id="of_feed_select">
					<label><?= __('Feed:') ?></label>
					<select dojoType="dijit.form.Select" name="feed_id">
						<option value="">--</option>
						<?php foreach ($available_feeds as $f) { ?>
							<option value="<?= (int)$f['id'] ?>"><?= htmlspecialchars($f['title']) ?></option>
						<?php } ?>
					</select>
				</fieldset>

				<fieldset id="of_cat_select" style="display: none">
					<label><?= __('Kategorie:') ?></label>
					<select dojoType="dijit.form.Select" name="cat_id">
						<option value="">--</option>
						<?php foreach ($available_cats as $c) { ?>
							<option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['title']) ?></option>
						<?php } ?>
					</select>
				</fieldset>

				<fieldset id="of_label_select" style="display: none">
					<label><?= __('Label:') ?></label>
					<select dojoType="dijit.form.Select" name="label_id">
						<option value="">--</option>
						<?php foreach ($available_labels as $l) { ?>
							<option value="<?= (int)$l['id'] ?>"><?= htmlspecialchars($l['caption']) ?></option>
						<?php } ?>
					</select>
				</fieldset>

				<fieldset id="of_tag_input" style="display: none">
					<label><?= __('Tag:') ?></label>
					<input dojoType="dijit.form.TextBox" name="tag"
						style="width: 200px"
						placeholder="<?= __('Tag-Name') ?>">
				</fieldset>

				<fieldset>
					<label><?= __('Maximale Einträge:') ?></label>
					<input dojoType="dijit.form.NumberSpinner"
						name="max_items"
						value="50"
						constraints="{min: 5, max: 200}"
						style="width: 100px">
				</fieldset>

				<hr/>
				<?= \Controls\submit_tag(__("Feed erstellen")) ?>
			</form>
		</div>
		<?php
	}

	function create_feed(): void {
		$owner_uid = $_SESSION['uid'];
		$title = clean($_POST["title"] ?? "");
		$source_type = clean($_POST["source_type"] ?? "");
		$max_items = (int)($_POST["max_items"] ?? 50);
		$access_key = bin2hex(random_bytes(16));

		if (empty($title)) {
			echo __("Bitte einen Titel angeben.");
			return;
		}

		$feed_id = null;
		$cat_id = null;
		$label_id = null;
		$tag = null;

		switch ($source_type) {
			case 'feed':
				$feed_id = (int)($_POST["feed_id"] ?? 0) ?: null;
				break;
			case 'cat':
				$cat_id = (int)($_POST["cat_id"] ?? 0) ?: null;
				break;
			case 'label':
				$label_id = (int)($_POST["label_id"] ?? 0) ?: null;
				break;
			case 'tag':
				$tag = clean($_POST["tag"] ?? "") ?: null;
				break;
		}

		$sth = $this->pdo->prepare("INSERT INTO ttrss_plugin_output_feeds
			(owner_uid, title, feed_id, cat_id, label_id, tag, access_key, max_items)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
		$sth->execute([$owner_uid, $title, $feed_id, $cat_id, $label_id, $tag, $access_key, $max_items]);

		echo __("Ausgabe-Feed erstellt.");
	}

	function delete_feed(): void {
		$owner_uid = $_SESSION['uid'];
		$id = (int)($_POST["feed_id"] ?? 0);

		$sth = $this->pdo->prepare("DELETE FROM ttrss_plugin_output_feeds
			WHERE id = ? AND owner_uid = ?");
		$sth->execute([$id, $owner_uid]);

		echo __("Ausgabe-Feed gelöscht.");
	}

	function save(): void {
		echo __("Einstellungen gespeichert.");
	}

	function api_version() {
		return 2;
	}
}
