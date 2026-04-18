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
		$host->add_hook($host::HOOK_SEARCH, $this);

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

	function get_prefs_js() {
		return file_get_contents(__DIR__ . "/annotations_prefs.js");
	}

	function get_prefs_css() {
		return file_get_contents(__DIR__ . "/annotations.css");
	}

	function csrf_ignore($method): bool {
		// Nur GET-basierte Downloads und Lesezugriffe überspringen;
		// add_article_tag ist schreibend und benötigt CSRF-Schutz
		return in_array($method, ["export_csv", "export_all_csv"]);
	}

	function hook_search($query) {
		if (!preg_match('/^marker:\s*(.+)/i', $query, $m)) {
			return [];
		}

		$marker = trim($m[1]);
		$pdo = Db::pdo();
		$sth = $pdo->prepare("SELECT DISTINCT ref_id FROM ttrss_plugin_annotations
			WHERE owner_uid = ? AND markers ILIKE ?");
		$sth->execute([$_SESSION['uid'], '%' . $marker . '%']);

		$ids = [];
		while ($row = $sth->fetch()) {
			$ids[] = (int) $row['ref_id'];
		}

		if (empty($ids)) {
			return ["ttrss_entries.id IN (-1)", [$marker]];
		}

		$id_list = implode(',', $ids);
		return ["ttrss_entries.id IN ($id_list)", [$marker]];
	}

	function hook_render_article_cdm($article) {
		return $this->inject_annotations($article);
	}

	function hook_render_article($article) {
		return $this->inject_annotations($article);
	}

	private function inject_annotations(array $article): array {
		$id = $article['id'] ?? 0;
		if (!$id) return $article;

		$sth = $this->pdo->prepare("SELECT id, selector_path, start_offset, end_offset,
			highlighted_text, note, color, markers FROM ttrss_plugin_annotations
			WHERE ref_id = ? AND owner_uid = ? ORDER BY id");
		$sth->execute([$id, $_SESSION['uid']]);

		$annotations = [];
		while ($row = $sth->fetch()) {
			$annotations[] = $row;
		}

		$json = htmlspecialchars(json_encode($annotations), ENT_QUOTES);
		$article['content'] = "<div class='annotations-wrapper' data-annotations='$json'
			data-article-id='$id'>" . $article['content'] . "</div>";

		return $article;
	}

	function hook_article_button($line) {
		$id = (int) $line['id'];

		$sth = $this->pdo->prepare("SELECT COUNT(*) AS cnt FROM ttrss_plugin_annotations
			WHERE ref_id = ? AND owner_uid = ?");
		$sth->execute([$id, $_SESSION['uid']]);
		$row = $sth->fetch();
		$count = (int)($row['cnt'] ?? 0);

		$badge = $count > 0 ? "<span class='ann-count-badge'>$count</span>" : "";

		return "<span class='ann-btn-wrap'><i class='material-icons'
			onclick=\"Plugins.Annotations.showPanel(" . $id . ")\"
			style='cursor:pointer'
			title=\"" . __('Annotationen') . "\">highlight</i>$badge</span>";
	}

	function hook_prefs_tab($args) {
		if ($args != "prefPrefs") return;

		?>
		<div dojoType="dijit.layout.AccordionPane"
			title="<i class='material-icons'>highlight</i> <?= __('Annotationen') ?>"
			onShow="Annotations_Prefs.init()">

			<div id="ann-prefs-stats" class="ann-prefs-stats"></div>

			<div class="ann-prefs-toolbar" id="ann-prefs-toolbar">
				<div class="ann-prefs-filters">
					<label class="ann-filter-label"><?= __('Von') ?>:
						<input type="date" id="ann-filter-date-from" onchange="Annotations_Prefs.loadPage(1)">
					</label>
					<label class="ann-filter-label"><?= __('Bis') ?>:
						<input type="date" id="ann-filter-date-to" onchange="Annotations_Prefs.loadPage(1)">
					</label>
					<label class="ann-filter-label"><?= __('Domain') ?>:
						<select id="ann-filter-domain" onchange="Annotations_Prefs.loadPage(1)">
							<option value=""><?= __('Alle') ?></option>
						</select>
					</label>
					<button class="btn btn-xs" onclick="Annotations_Prefs.resetFilters()">
						<i class="material-icons">clear</i> <?= __('Filter zurücksetzen') ?>
					</button>
				</div>
				<button class="btn btn-xs" onclick="Annotations_Prefs.exportAllCsv()">
					<i class="material-icons">download</i> <?= __('Alle exportieren (CSV)') ?>
				</button>
			</div>

			<div id="ann-prefs-content"></div>

			<div id="ann-prefs-pagination" class="ann-prefs-pagination"></div>
		</div>
		<?php
	}

	function get_prefs_data(): void {
		$page = max(1, (int)clean($_REQUEST['page'] ?? 1));
		$per_page = 50;
		$date_from = clean($_REQUEST['date_from'] ?? '');
		$date_to = clean($_REQUEST['date_to'] ?? '');
		$domain = clean($_REQUEST['domain'] ?? '');

		// Filter-Bedingungen aufbauen
		$conditions = ['a.owner_uid = ?'];
		$params = [$_SESSION['uid']];

		if ($date_from) {
			$conditions[] = 'a.created_at >= ?::date';
			$params[] = $date_from;
		}
		if ($date_to) {
			$conditions[] = "a.created_at < (?::date + interval '1 day')";
			$params[] = $date_to;
		}
		if ($domain) {
			$conditions[] = "e.link LIKE ?";
			$params[] = '%://' . $domain . '/%';
		}

		$where = implode(' AND ', $conditions);

		// Gesamtzahlen (ungefiltert)
		$sth = $this->pdo->prepare("SELECT COUNT(*) as total_annotations,
			COUNT(DISTINCT a.ref_id) as total_articles
			FROM ttrss_plugin_annotations a
			WHERE a.owner_uid = ?");
		$sth->execute([$_SESSION['uid']]);
		$totals = $sth->fetch();

		// Gefilterte Zahlen
		$sth = $this->pdo->prepare("SELECT COUNT(*) as total_annotations,
			COUNT(DISTINCT a.ref_id) as total_articles
			FROM ttrss_plugin_annotations a
			LEFT JOIN ttrss_entries e ON e.id = a.ref_id
			WHERE $where");
		$sth->execute($params);
		$filtered_totals = $sth->fetch();

		// Verfügbare Domains (ungefiltert, für Dropdown)
		$sth = $this->pdo->prepare("SELECT DISTINCT
			regexp_replace(e.link, '^https?://([^/]+).*$', '\\1') as domain
			FROM ttrss_plugin_annotations a
			LEFT JOIN ttrss_entries e ON e.id = a.ref_id
			WHERE a.owner_uid = ? AND e.link IS NOT NULL AND e.link != ''
			ORDER BY domain");
		$sth->execute([$_SESSION['uid']]);
		$domains = [];
		while ($row = $sth->fetch()) {
			if (!empty($row['domain'])) $domains[] = $row['domain'];
		}

		// Gruppen mit Annotationszahl (gefiltert)
		$sth = $this->pdo->prepare("SELECT a.ref_id, COUNT(*) as ann_count,
			e.title AS article_title, e.link
			FROM ttrss_plugin_annotations a
			LEFT JOIN ttrss_entries e ON e.id = a.ref_id
			WHERE $where
			GROUP BY a.ref_id, e.title, e.link
			ORDER BY MAX(a.created_at) DESC");
		$sth->execute($params);

		$all_groups = [];
		while ($row = $sth->fetch()) {
			$all_groups[] = $row;
		}

		// Paginierung: Gruppen durchlaufen, Annotationen zählen
		// Sobald ≥50 erreicht, aktuellen Artikel noch fertigstellen, dann neue Seite
		$pages = [];
		$current_page_groups = [];
		$current_count = 0;
		$page_num = 1;

		foreach ($all_groups as $group) {
			if ($current_count >= $per_page && !empty($current_page_groups)) {
				$pages[$page_num] = $current_page_groups;
				$page_num++;
				$current_page_groups = [];
				$current_count = 0;
			}
			$current_page_groups[] = $group;
			$current_count += (int)$group['ann_count'];
		}
		if (!empty($current_page_groups)) {
			$pages[$page_num] = $current_page_groups;
		}

		$total_pages = count($pages);
		$page = min($page, max(1, $total_pages));

		// Detail-Annotationen nur für aktuelle Seite laden
		$groups = [];
		if (isset($pages[$page])) {
			$ref_ids = array_map(function($g) { return (int)$g['ref_id']; }, $pages[$page]);

			if (!empty($ref_ids)) {
				$placeholders = implode(',', array_fill(0, count($ref_ids), '?'));

				$detail_conditions = $conditions;
				$detail_params = $params;
				$detail_conditions[] = "a.ref_id IN ($placeholders)";
				$detail_params = array_merge($detail_params, $ref_ids);
				$detail_where = implode(' AND ', $detail_conditions);

				$sth = $this->pdo->prepare("SELECT a.id, a.ref_id, a.highlighted_text, a.note, a.color, a.markers,
					to_char(a.created_at, 'DD.MM.YYYY HH24:MI') as formatted_date,
					e.title AS article_title, e.link
					FROM ttrss_plugin_annotations a
					LEFT JOIN ttrss_entries e ON e.id = a.ref_id
					WHERE $detail_where
					ORDER BY a.ref_id, a.created_at DESC");
				$sth->execute($detail_params);

				$grouped = [];
				while ($row = $sth->fetch()) {
					$ref_id = $row['ref_id'];
					if (!isset($grouped[$ref_id])) {
						$grouped[$ref_id] = [
							'title' => $row['article_title'] ?? 'Unbekannter Artikel',
							'ref_id' => $ref_id,
							'link' => $row['link'] ?? '',
							'annotations' => []
						];
					}
					$grouped[$ref_id]['annotations'][] = [
						'id' => $row['id'],
						'highlighted_text' => $row['highlighted_text'],
						'note' => $row['note'],
						'color' => $row['color'],
						'markers' => $row['markers'],
						'formatted_date' => $row['formatted_date']
					];
				}

				// Reihenfolge aus der Gruppen-Abfrage beibehalten
				foreach ($ref_ids as $rid) {
					if (isset($grouped[$rid])) {
						$groups[] = $grouped[$rid];
					}
				}
			}
		}

		print json_encode([
			'total_annotations' => (int)$totals['total_annotations'],
			'total_articles' => (int)$totals['total_articles'],
			'filtered_annotations' => (int)$filtered_totals['total_annotations'],
			'filtered_articles' => (int)$filtered_totals['total_articles'],
			'domains' => $domains,
			'groups' => $groups,
			'page' => $page,
			'total_pages' => $total_pages
		]);
	}

	function save_annotation(): void {
		$ref_id = (int)clean($_REQUEST['ref_id'] ?? 0);
		$highlighted_text = clean($_REQUEST['highlighted_text'] ?? '');
		$note = clean($_REQUEST['note'] ?? '');
		$color_raw = clean($_REQUEST['color'] ?? '#fff3cd');
		$color = preg_match('/^#[0-9a-fA-F]{3,8}$/', $color_raw) ? $color_raw : '#fff3cd';
		$start_offset = (int)clean($_REQUEST['start_offset'] ?? 0);
		$end_offset = (int)clean($_REQUEST['end_offset'] ?? 0);
		$selector_path = clean($_REQUEST['selector_path'] ?? '');
		$markers = clean($_REQUEST['markers'] ?? '');

		if (!$ref_id || empty($highlighted_text)) {
			print json_encode(['error' => 'Fehlende Daten']);
			return;
		}

		$sth = $this->pdo->prepare("INSERT INTO ttrss_plugin_annotations
			(ref_id, owner_uid, selector_path, start_offset, end_offset, highlighted_text, note, color, markers)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
		$sth->execute([$ref_id, $_SESSION['uid'], $selector_path,
			$start_offset, $end_offset, $highlighted_text, $note, $color, $markers]);

		$id = $this->pdo->lastInsertId();

		print json_encode(['id' => $id, 'status' => 'ok']);
	}

	function update_annotation(): void {
		$id = (int)clean($_REQUEST['id'] ?? 0);
		$note = clean($_REQUEST['note'] ?? '');
		$color_raw = clean($_REQUEST['color'] ?? '#fff3cd');
		$color = preg_match('/^#[0-9a-fA-F]{3,8}$/', $color_raw) ? $color_raw : '#fff3cd';
		$markers = clean($_REQUEST['markers'] ?? '');

		if (!$id) {
			print json_encode(['error' => 'Fehlende ID']);
			return;
		}

		$sth = $this->pdo->prepare("UPDATE ttrss_plugin_annotations
			SET note = ?, color = ?, markers = ? WHERE id = ? AND owner_uid = ?");
		$sth->execute([$note, $color, $markers, $id, $_SESSION['uid']]);

		print json_encode(['status' => 'ok']);
	}

	function get_annotations(): void {
		$ref_id = (int)clean($_REQUEST['article_id'] ?? 0);

		$sth = $this->pdo->prepare("SELECT id, selector_path, start_offset, end_offset,
			highlighted_text, note, color, markers FROM ttrss_plugin_annotations
			WHERE ref_id = ? AND owner_uid = ? ORDER BY id");
		$sth->execute([$ref_id, $_SESSION['uid']]);

		$result = [];
		while ($row = $sth->fetch()) {
			$result[] = $row;
		}

		print json_encode($result);
	}

	function delete_annotation(): void {
		$id = (int)clean($_REQUEST['id'] ?? 0);

		$sth = $this->pdo->prepare("DELETE FROM ttrss_plugin_annotations
			WHERE id = ? AND owner_uid = ?");
		$sth->execute([$id, $_SESSION['uid']]);

		print json_encode(['status' => 'ok']);
	}

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

	function view_article(): void {
		$ref_id = (int)clean($_REQUEST['ref_id'] ?? 0);

		$sth = $this->pdo->prepare("SELECT e.title, e.content, e.cached_content, e.link
			FROM ttrss_entries e
			WHERE e.id = ? AND EXISTS (
				SELECT 1 FROM ttrss_user_entries WHERE ref_id = e.id AND owner_uid = ?
			)");
		$sth->execute([$ref_id, $_SESSION['uid']]);
		$row = $sth->fetch();

		if (!$row) {
			print json_encode(['error' => 'Artikel nicht gefunden']);
			return;
		}

		$content = !empty($row['cached_content']) ? $row['cached_content'] : $row['content'];
		$content = Sanitizer::sanitize($content, false, $_SESSION['uid']);

		print json_encode([
			'title' => $row['title'],
			'content' => $content,
			'link' => $row['link']
		]);
	}

	function export_csv(): void {
		$ref_id = (int)clean($_REQUEST['ref_id'] ?? 0);

		$sth = $this->pdo->prepare("SELECT a.highlighted_text, a.note, a.color, a.markers,
			to_char(a.created_at, 'DD.MM.YYYY HH24:MI') as formatted_date,
			e.title AS article_title, e.link AS article_url
			FROM ttrss_plugin_annotations a
			LEFT JOIN ttrss_entries e ON e.id = a.ref_id
			WHERE a.ref_id = ? AND a.owner_uid = ?
			ORDER BY a.created_at");
		$sth->execute([$ref_id, $_SESSION['uid']]);

		header('Content-Type: text/csv; charset=utf-8');
		header('Content-Disposition: attachment; filename="annotationen_artikel_' . $ref_id . '.csv"');

		$out = fopen('php://output', 'w');
		fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
		fputcsv($out, ['Artikel', 'URL', 'Markierter Text', 'Notiz', 'Farbe', 'Marker', 'Datum'], ';');

		while ($row = $sth->fetch()) {
			fputcsv($out, [
				$row['article_title'] ?? '',
				$row['article_url'] ?? '',
				$row['highlighted_text'],
				$row['note'],
				$row['color'],
				$row['markers'],
				$row['formatted_date']
			], ';');
		}

		fclose($out);
	}

	function export_all_csv(): void {
		$sth = $this->pdo->prepare("SELECT a.highlighted_text, a.note, a.color, a.markers,
			to_char(a.created_at, 'DD.MM.YYYY HH24:MI') as formatted_date,
			e.title AS article_title, e.link AS article_url
			FROM ttrss_plugin_annotations a
			LEFT JOIN ttrss_entries e ON e.id = a.ref_id
			WHERE a.owner_uid = ?
			ORDER BY e.title, a.created_at");
		$sth->execute([$_SESSION['uid']]);

		header('Content-Type: text/csv; charset=utf-8');
		header('Content-Disposition: attachment; filename="annotationen_alle.csv"');

		$out = fopen('php://output', 'w');
		fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
		fputcsv($out, ['Artikel', 'URL', 'Markierter Text', 'Notiz', 'Farbe', 'Marker', 'Datum'], ';');

		while ($row = $sth->fetch()) {
			fputcsv($out, [
				$row['article_title'] ?? '',
				$row['article_url'] ?? '',
				$row['highlighted_text'],
				$row['note'],
				$row['color'],
				$row['markers'],
				$row['formatted_date']
			], ';');
		}

		fclose($out);
	}

	function get_article_tags(): void {
		$id = (int)clean($_REQUEST['id'] ?? 0);
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

	function add_article_tag(): void {
		$id = (int)clean($_REQUEST['id'] ?? 0);
		$tag = mb_strtolower(mb_substr(trim(clean($_REQUEST['tag'] ?? '')), 0, 250));
		$owner_uid = $_SESSION['uid'];

		if (!$id || $tag === '') {
			print json_encode(['error' => 'Fehlende Daten']);
			return;
		}

		$sth = $this->pdo->prepare("SELECT int_id FROM ttrss_user_entries
			WHERE ref_id = ? AND owner_uid = ?");
		$sth->execute([$id, $owner_uid]);
		$row = $sth->fetch();

		if (!$row) {
			print json_encode(['error' => 'Artikel nicht gefunden']);
			return;
		}

		$int_id = $row['int_id'];

		// Nur einfügen wenn Tag noch nicht vorhanden
		$sth = $this->pdo->prepare("INSERT INTO ttrss_tags (tag_name, owner_uid, post_int_id)
			SELECT ?, ?, ?
			WHERE NOT EXISTS (
				SELECT 1 FROM ttrss_tags WHERE tag_name = ? AND owner_uid = ? AND post_int_id = ?
			)");
		$sth->execute([$tag, $owner_uid, $int_id, $tag, $owner_uid, $int_id]);

		// Tag-Cache aktualisieren
		$sth = $this->pdo->prepare("SELECT tag_name FROM ttrss_tags
			WHERE post_int_id = ? AND owner_uid = ? ORDER BY tag_name");
		$sth->execute([$int_id, $owner_uid]);
		$all_tags = [];
		while ($r = $sth->fetch()) {
			$all_tags[] = $r['tag_name'];
		}

		$sth = $this->pdo->prepare("UPDATE ttrss_user_entries SET tag_cache = ?
			WHERE int_id = ? AND owner_uid = ?");
		$sth->execute([implode(',', $all_tags), $int_id, $owner_uid]);

		print json_encode(['status' => 'ok', 'tag' => $tag]);
	}

	function get_all_markers(): void {
		$sth = $this->pdo->prepare("SELECT DISTINCT unnest(string_to_array(markers, ',')) AS marker
			FROM ttrss_plugin_annotations
			WHERE owner_uid = ? AND markers != ''
			ORDER BY marker");
		$sth->execute([$_SESSION['uid']]);

		$markers = [];
		while ($row = $sth->fetch()) {
			$m = trim($row['marker']);
			if ($m !== '') $markers[] = $m;
		}

		print json_encode(array_values(array_unique($markers)));
	}

	function api_version() {
		return 2;
	}
}
