<?php
class Annotations extends Plugin {

	/** @var PluginHost $host */
	private $host;

	function about() {
		return [1.0,
			"Textpassagen in Artikeln hervorheben und annotieren",
			"tt-ss",
			false];
	}

	function init($host) {
		$this->host = $host;

		$host->add_hook($host::HOOK_RENDER_ARTICLE_CDM, $this);
		$host->add_hook($host::HOOK_RENDER_ARTICLE, $this);
		$host->add_hook($host::HOOK_ARTICLE_BUTTON, $this);
		$host->add_hook($host::HOOK_PREFS_TAB, $this);

		$m = new Db_Migrations();
		$m->initialize_for_plugin($this);
		$m->migrate();
	}

	function get_js() {
		return file_get_contents(__DIR__ . "/annotations.js");
	}

	function get_css() {
		return file_get_contents(__DIR__ . "/annotations.css");
	}

	/**
	 * Annotationsdaten als data-Attribut am Artikel anhängen (CDM).
	 * @param array<string, mixed> $article
	 * @return array<string, mixed>
	 */
	function hook_render_article_cdm($article) {
		return $this->inject_annotations($article);
	}

	/**
	 * Annotationsdaten als data-Attribut am Artikel anhängen (Drei-Spalten-Modus).
	 * @param array<string, mixed> $article
	 * @return array<string, mixed>
	 */
	function hook_render_article($article) {
		return $this->inject_annotations($article);
	}

	/**
	 * @param array<string, mixed> $article
	 * @return array<string, mixed>
	 */
	private function inject_annotations(array $article): array {
		$id = $article['id'] ?? 0;
		if (!$id) return $article;

		$sth = $this->pdo->prepare("SELECT id, selector_path, start_offset, end_offset,
			highlighted_text, note, color FROM ttrss_plugin_annotations
			WHERE ref_id = ? AND owner_uid = ? ORDER BY id");
		$sth->execute([$id, $_SESSION['uid']]);

		$annotations = [];
		while ($row = $sth->fetch()) {
			$annotations[] = $row;
		}

		if (!empty($annotations)) {
			$json = htmlspecialchars(json_encode($annotations), ENT_QUOTES);
			$article['content'] = "<div class='annotations-wrapper' data-annotations='$json'
				data-article-id='$id'>" . $article['content'] . "</div>";
		} else {
			$article['content'] = "<div class='annotations-wrapper'
				data-annotations='[]' data-article-id='$id'>" . $article['content'] . "</div>";
		}

		return $article;
	}

	/**
	 * Annotationsanzahl-Badge als Artikelbutton.
	 * @param array<string, mixed> $line
	 * @return string
	 */
	function hook_article_button($line) {
		$id = (int) $line['id'];

		$sth = $this->pdo->prepare("SELECT COUNT(*) AS cnt FROM ttrss_plugin_annotations
			WHERE ref_id = ? AND owner_uid = ?");
		$sth->execute([$id, $_SESSION['uid']]);
		$row = $sth->fetch();
		$count = (int)($row['cnt'] ?? 0);

		$badge = $count > 0 ? "<span class='ann-count-badge'>$count</span>" : "";

		return "<i class='material-icons ann-btn'
			onclick=\"Plugins.Annotations.showPanel(" . $id . ")\"
			style='cursor: pointer; position: relative'
			title=\"" . __('Annotationen') . "\">edit_note$badge</i>";
	}

	/**
	 * Einstellungsseite: Alle Annotationen anzeigen.
	 */
	function hook_prefs_tab($args) {
		if ($args != "prefPrefs") return;

		$sth = $this->pdo->prepare("SELECT a.*, e.title AS article_title
			FROM ttrss_plugin_annotations a
			LEFT JOIN ttrss_entries e ON e.id = a.ref_id
			WHERE a.owner_uid = ?
			ORDER BY a.created_at DESC
			LIMIT 200");
		$sth->execute([$_SESSION['uid']]);

		?>
		<div dojoType="dijit.layout.AccordionPane"
			title="<i class='material-icons'>edit_note</i> <?= __('Annotationen') ?>">

			<div class="ann-prefs-list">
				<table width="100%">
					<tr class="title">
						<td><?= __('Artikel') ?></td>
						<td><?= __('Markierter Text') ?></td>
						<td><?= __('Notiz') ?></td>
						<td><?= __('Datum') ?></td>
						<td></td>
					</tr>
					<?php while ($row = $sth->fetch()) { ?>
					<tr>
						<td><?= htmlspecialchars($row['article_title'] ?? '') ?></td>
						<td><span class="ann-highlight-preview" style="background: <?= htmlspecialchars($row['color']) ?>">
							<?= htmlspecialchars(mb_substr($row['highlighted_text'], 0, 80)) ?></span></td>
						<td><?= htmlspecialchars(mb_substr($row['note'], 0, 100)) ?></td>
						<td><?= htmlspecialchars($row['created_at']) ?></td>
						<td>
							<i class="material-icons" style="cursor:pointer"
								onclick="Plugins.Annotations.deleteFromPrefs(<?= (int)$row['id'] ?>)"
								title="<?= __('Löschen') ?>">delete</i>
						</td>
					</tr>
					<?php } ?>
				</table>
			</div>
		</div>
		<?php
	}

	/**
	 * Annotation speichern (AJAX).
	 */
	function save_annotation(): void {
		$ref_id = (int)clean($_REQUEST['ref_id'] ?? 0);
		$highlighted_text = clean($_REQUEST['highlighted_text'] ?? '');
		$note = clean($_REQUEST['note'] ?? '');
		$color_raw = clean($_REQUEST['color'] ?? '#fff3cd');
		// Farbe auf gültiges Hex-Format einschränken (CSS-Injection verhindern)
		$color = preg_match('/^#[0-9a-fA-F]{3,8}$/', $color_raw) ? $color_raw : '#fff3cd';
		$start_offset = (int)clean($_REQUEST['start_offset'] ?? 0);
		$end_offset = (int)clean($_REQUEST['end_offset'] ?? 0);
		$selector_path = clean($_REQUEST['selector_path'] ?? '');

		if (!$ref_id || empty($highlighted_text)) {
			print json_encode(['error' => 'Fehlende Daten']);
			return;
		}

		$sth = $this->pdo->prepare("INSERT INTO ttrss_plugin_annotations
			(ref_id, owner_uid, selector_path, start_offset, end_offset, highlighted_text, note, color)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
		$sth->execute([$ref_id, $_SESSION['uid'], $selector_path,
			$start_offset, $end_offset, $highlighted_text, $note, $color]);

		$id = $this->pdo->lastInsertId();

		print json_encode(['id' => $id, 'status' => 'ok']);
	}

	/**
	 * Annotationen für einen Artikel abrufen (AJAX).
	 */
	function get_annotations(): void {
		$ref_id = (int)clean($_REQUEST['article_id'] ?? 0);

		$sth = $this->pdo->prepare("SELECT id, selector_path, start_offset, end_offset,
			highlighted_text, note, color FROM ttrss_plugin_annotations
			WHERE ref_id = ? AND owner_uid = ? ORDER BY id");
		$sth->execute([$ref_id, $_SESSION['uid']]);

		$result = [];
		while ($row = $sth->fetch()) {
			$result[] = $row;
		}

		print json_encode($result);
	}

	/**
	 * Annotation löschen (AJAX).
	 */
	function delete_annotation(): void {
		$id = (int)clean($_REQUEST['id'] ?? 0);

		$sth = $this->pdo->prepare("DELETE FROM ttrss_plugin_annotations
			WHERE id = ? AND owner_uid = ?");
		$sth->execute([$id, $_SESSION['uid']]);

		print json_encode(['status' => 'ok']);
	}

	/**
	 * Alle Annotationen des Benutzers abrufen (AJAX für Prefs).
	 */
	function get_all_annotations(): void {
		$sth = $this->pdo->prepare("SELECT a.*, e.title AS article_title
			FROM ttrss_plugin_annotations a
			LEFT JOIN ttrss_entries e ON e.id = a.ref_id
			WHERE a.owner_uid = ?
			ORDER BY a.created_at DESC");
		$sth->execute([$_SESSION['uid']]);

		$result = [];
		while ($row = $sth->fetch()) {
			$result[] = $row;
		}

		print json_encode($result);
	}

	function api_version() {
		return 2;
	}
}
