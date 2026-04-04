<?php
class Enhanced_Tags extends Plugin {

	/** @var PluginHost $host */
	private $host;

	function about() {
		return [1.0,
			"Erweitertes Tagging: Autocomplete, Tag-Cloud und Kreuzsuche",
			"tt-ss",
			false];
	}

	function init($host) {
		$this->host = $host;

		$host->add_hook($host::HOOK_ARTICLE_BUTTON, $this);
		$host->add_hook($host::HOOK_PREFS_TAB, $this);
		$host->add_hook($host::HOOK_RENDER_ARTICLE_CDM, $this);
		$host->add_hook($host::HOOK_RENDER_ARTICLE, $this);
	}

	function get_js() {
		return file_get_contents(__DIR__ . "/enhanced_tags.js");
	}

	function get_css() {
		return file_get_contents(__DIR__ . "/enhanced_tags.css");
	}

	function hook_article_button($line) {
		$id = $line['id'];
		$tag_cache = $line['tag_cache'] ?? '';
		$has_tags = !empty(trim($tag_cache));

		return "<i class='material-icons et-tag-btn " . ($has_tags ? 'et-has-tags' : '') . "'
			data-article-id='$id'
			onclick=\"Plugins.Enhanced_Tags.edit($id)\"
			style='cursor: pointer'
			title=\"" . __('Tags bearbeiten') . "\">sell</i>";
	}

	/**
	 * Zeigt aktuelle Tags unter dem Artikel-Content an.
	 * @param array<string, mixed> $article
	 * @return array<string, mixed>
	 */
	private function render_tags(array $article): array {
		$tags = $article['tags'] ?? [];
		if (empty($tags)) return $article;

		$tag_html = '<div class="et-article-tags">';
		foreach ($tags as $tag) {
			$tag = htmlspecialchars(trim($tag));
			if (empty($tag)) continue;
			$tag_html .= "<span class='et-tag-chip' onclick=\"Plugins.Enhanced_Tags.search('" . addslashes($tag) . "')\"
				title=\"" . sprintf(__('Nach Tag "%s" suchen'), $tag) . "\">#$tag</span>";
		}
		$tag_html .= '</div>';

		$article['content'] = $tag_html . $article['content'];

		return $article;
	}

	function hook_render_article_cdm($article) {
		return $this->render_tags($article);
	}

	function hook_render_article($article) {
		return $this->render_tags($article);
	}

	/**
	 * Gibt alle Tags des Benutzers mit Häufigkeit zurück (AJAX).
	 */
	function get_all_tags(): void {
		$owner_uid = $_SESSION['uid'];

		$sth = $this->pdo->prepare("SELECT tag_name, COUNT(*) AS cnt
			FROM ttrss_tags
			WHERE owner_uid = ?
			GROUP BY tag_name
			ORDER BY cnt DESC
			LIMIT 200");
		$sth->execute([$owner_uid]);

		$tags = [];
		while ($row = $sth->fetch()) {
			$tags[] = [
				'name' => $row['tag_name'],
				'count' => (int)$row['cnt']
			];
		}

		print json_encode($tags);
	}

	/**
	 * Gibt Tags eines einzelnen Artikels zurück (AJAX).
	 */
	function get_article_tags(): void {
		$id = (int) clean($_REQUEST['id'] ?? 0);
		$owner_uid = $_SESSION['uid'];

		$sth = $this->pdo->prepare("SELECT tag_name FROM ttrss_tags
			WHERE post_int_id = (
				SELECT int_id FROM ttrss_user_entries WHERE ref_id = ? AND owner_uid = ?
			) AND owner_uid = ?
			ORDER BY tag_name");
		$sth->execute([$id, $owner_uid, $owner_uid]);

		$tags = [];
		while ($row = $sth->fetch()) {
			$tags[] = $row['tag_name'];
		}

		print json_encode($tags);
	}

	/**
	 * Setzt die Tags eines Artikels (AJAX).
	 */
	function save_tags(): void {
		$id = (int) clean($_REQUEST['id'] ?? 0);
		$tags_str = clean($_REQUEST['tags'] ?? '');
		$owner_uid = $_SESSION['uid'];

		// int_id des Artikels holen
		$sth = $this->pdo->prepare("SELECT int_id FROM ttrss_user_entries
			WHERE ref_id = ? AND owner_uid = ?");
		$sth->execute([$id, $owner_uid]);
		$row = $sth->fetch();
		if (!$row) return;

		$int_id = $row['int_id'];

		// Bestehende Tags löschen
		$sth = $this->pdo->prepare("DELETE FROM ttrss_tags
			WHERE post_int_id = ? AND owner_uid = ?");
		$sth->execute([$int_id, $owner_uid]);

		// Neue Tags einfügen
		$tags = array_unique(array_filter(
			array_map('trim', explode(',', $tags_str)),
			fn($t) => mb_strlen($t) > 0
		));

		$sth = $this->pdo->prepare("INSERT INTO ttrss_tags (tag_name, owner_uid, post_int_id)
			VALUES (?, ?, ?)");

		foreach ($tags as $tag) {
			$tag = mb_strtolower(mb_substr($tag, 0, 250));
			$sth->execute([$tag, $owner_uid, $int_id]);
		}

		// Tag-Cache im User-Entry aktualisieren
		$tag_cache = implode(',', $tags);
		$sth2 = $this->pdo->prepare("UPDATE ttrss_user_entries SET tag_cache = ?
			WHERE ref_id = ? AND owner_uid = ?");
		$sth2->execute([$tag_cache, $id, $owner_uid]);

		print json_encode([
			"id" => $id,
			"tags" => $tags
		]);
	}

	function hook_prefs_tab($args) {
		if ($args != "prefPrefs") return;

		$owner_uid = $_SESSION['uid'];

		// Tag-Cloud laden
		$sth = $this->pdo->prepare("SELECT tag_name, COUNT(*) AS cnt
			FROM ttrss_tags
			WHERE owner_uid = ?
			GROUP BY tag_name
			ORDER BY cnt DESC
			LIMIT 100");
		$sth->execute([$owner_uid]);

		$total_tags = 0;
		$tag_cloud = [];
		$max_count = 1;
		while ($row = $sth->fetch()) {
			$tag_cloud[] = $row;
			$total_tags += $row['cnt'];
			$max_count = max($max_count, $row['cnt']);
		}

		?>
		<div dojoType="dijit.layout.AccordionPane"
			title="<i class='material-icons'>sell</i> <?= __('Tags verwalten') ?>">

			<p><strong><?= count($tag_cloud) ?></strong> <?= __('verschiedene Tags') ?>,
				<strong><?= $total_tags ?></strong> <?= __('Zuweisungen gesamt') ?></p>

			<?php if (!empty($tag_cloud)) { ?>
				<div class="et-tag-cloud panel panel-scrollable" style="max-height: 200px;">
					<?php foreach ($tag_cloud as $tag) {
						$size = 12 + (int)(($tag['cnt'] / $max_count) * 14);
						$tag_name = htmlspecialchars($tag['tag_name']);
						?>
						<span class="et-cloud-tag" style="font-size: <?= $size ?>px"
							title="<?= $tag['cnt'] ?> Artikel">
							#<?= $tag_name ?>
						</span>
					<?php } ?>
				</div>
			<?php } else { ?>
				<p class="text-muted"><?= __('Noch keine Tags vorhanden. Füge Tags über den Tag-Button an Artikeln hinzu.') ?></p>
			<?php } ?>

			<hr/>

			<h3><?= __('Alle Tags löschen') ?></h3>
			<form dojoType="dijit.form.Form">
				<?= \Controls\pluginhandler_tags($this, "purge_tags") ?>
				<button dojoType="dijit.form.Button" type="submit" class="alt-danger">
					<i class="material-icons">delete_sweep</i> <?= __('Alle Tags entfernen') ?>
				</button>
			</form>
		</div>
		<?php
	}

	function purge_tags(): void {
		$sth = $this->pdo->prepare("DELETE FROM ttrss_tags WHERE owner_uid = ?");
		$sth->execute([$_SESSION['uid']]);

		// Tag-Caches leeren
		$sth = $this->pdo->prepare("UPDATE ttrss_user_entries SET tag_cache = '' WHERE owner_uid = ?");
		$sth->execute([$_SESSION['uid']]);

		echo __("Alle Tags wurden entfernt.");
	}

	function api_version() {
		return 2;
	}
}
