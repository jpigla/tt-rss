<?php
class Browser_Extension extends Plugin {

	/** @var PluginHost $host */
	private $host;

	/** Name des Später-Lesen-Labels (identisch mit Read_Later Plugin) */
	const READ_LATER_LABEL = '📌 Später lesen';

	function about() {
		return [2.0,
			"API-Endpunkt für Browser-Erweiterungen — Artikel speichern, Annotationen, Labels",
			"tt-ss",
			false];
	}

	function init($host) {
		$this->host = $host;

		$host->add_hook($host::HOOK_PREFS_TAB, $this);
	}

	/**
	 * Öffentliche Methoden (ohne Login).
	 */
	function is_public_method($method): bool {
		return in_array($method, [
			"save_article", "check",
			"get_status", "get_annotations_for_url",
			"save_annotation_for_url", "update_annotation_ext",
			"delete_annotation_ext", "get_labels",
			"set_labels_for_url", "toggle_read_later",
			"get_page_note", "set_page_note"
		]);
	}

	/**
	 * CSRF-Prüfung für öffentliche Methoden überspringen.
	 */
	function csrf_ignore($method): bool {
		return in_array($method, [
			"save_article", "check",
			"get_status", "get_annotations_for_url",
			"save_annotation_for_url", "update_annotation_ext",
			"delete_annotation_ext", "get_labels",
			"set_labels_for_url", "toggle_read_later",
			"get_page_note", "set_page_note"
		]);
	}

	// ─── Gemeinsame Helfer ──────────────────────────────────────

	/**
	 * CORS-Header und JSON-Content-Type für alle öffentlichen Endpunkte.
	 */
	private function send_api_headers(): void {
		header('Content-Type: application/json');

		$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
		// Chrome-Extension und localhost für Entwicklung erlauben
		if (preg_match('/^chrome-extension:\/\//', $origin)
			|| preg_match('/^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?$/', $origin)) {
			header('Access-Control-Allow-Origin: ' . $origin);
			header('Vary: Origin');
		} else {
			// Fallback für direkte Aufrufe (Bookmarklet etc.)
			header('Access-Control-Allow-Origin: *');
		}
		header('Access-Control-Allow-Methods: POST, OPTIONS');
		header('Access-Control-Allow-Headers: Content-Type');
	}

	/**
	 * OPTIONS-Preflight behandeln. Gibt true zurück wenn Preflight.
	 */
	private function handle_preflight(): bool {
		if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
			http_response_code(200);
			return true;
		}
		return false;
	}

	/**
	 * JSON-Body oder $_REQUEST lesen.
	 */
	private function read_input(): array {
		$input = json_decode(file_get_contents('php://input'), true);
		if (!is_array($input)) {
			$input = $_POST;
		}
		return $input;
	}

	/**
	 * API-Key aus Input validieren. Gibt owner_uid zurück oder sendet Fehler.
	 * @return int|false
	 */
	private function authenticate(array $input) {
		$api_key = $input['api_key'] ?? '';

		if (empty($api_key)) {
			http_response_code(403);
			print json_encode(['status' => 'error', 'message' => 'API-Schlüssel fehlt.']);
			return false;
		}

		$owner_uid = $this->validate_api_key($api_key);
		if (!$owner_uid) {
			http_response_code(403);
			print json_encode(['status' => 'error', 'message' => 'Ungültiger API-Schlüssel.']);
			return false;
		}

		return $owner_uid;
	}

	/**
	 * URL normalisieren: Fragment entfernen, trailing Slash, utm_* Parameter.
	 */
	private function normalize_url(string $url): string {
		// Fragment entfernen
		$url = preg_replace('/#.*$/', '', $url);

		// URL parsen
		$parts = parse_url($url);
		if (!$parts || empty($parts['host'])) return $url;

		// utm_* Parameter entfernen
		if (!empty($parts['query'])) {
			parse_str($parts['query'], $params);
			$params = array_filter($params, function ($key) {
				return !preg_match('/^utm_/i', $key);
			}, ARRAY_FILTER_USE_KEY);
			$parts['query'] = http_build_query($params);
		}

		// URL wieder zusammensetzen
		$result = ($parts['scheme'] ?? 'https') . '://' . $parts['host'];
		if (!empty($parts['port'])) $result .= ':' . $parts['port'];
		$result .= $parts['path'] ?? '/';
		if (!empty($parts['query'])) $result .= '?' . $parts['query'];

		// Trailing Slash entfernen (außer bei Root-Pfad)
		$path = $parts['path'] ?? '/';
		if ($path !== '/') {
			$result = rtrim($result, '/');
		}

		return $result;
	}

	/**
	 * Artikel-ref_id anhand einer URL finden.
	 * Erst GUID-Match (für über Extension gespeicherte Artikel), dann link-Match.
	 * @return int|null ref_id oder null
	 */
	private function find_article_by_url(string $url, int $owner_uid): ?int {
		$normalized = $this->normalize_url($url);

		// 1. GUID-Match (Artikel die über die Extension gespeichert wurden)
		$guid = 'SHA1:' . sha1("ttshared:" . $url . $owner_uid);
		$sth = $this->pdo->prepare("SELECT e.id FROM ttrss_entries e
			JOIN ttrss_user_entries ue ON ue.ref_id = e.id
			WHERE e.guid = ? AND ue.owner_uid = ? LIMIT 1");
		$sth->execute([$guid, $owner_uid]);

		if ($row = $sth->fetch()) {
			return (int) $row['id'];
		}

		// 1b. GUID-Match mit normalisierter URL
		if ($normalized !== $url) {
			$guid_norm = 'SHA1:' . sha1("ttshared:" . $normalized . $owner_uid);
			$sth->execute([$guid_norm, $owner_uid]);
			if ($row = $sth->fetch()) {
				return (int) $row['id'];
			}
		}

		// 2. Link-Match (Artikel aus RSS-Feeds)
		$sth = $this->pdo->prepare("SELECT e.id FROM ttrss_entries e
			JOIN ttrss_user_entries ue ON ue.ref_id = e.id
			WHERE e.link = ? AND ue.owner_uid = ? LIMIT 1");
		$sth->execute([$url, $owner_uid]);

		if ($row = $sth->fetch()) {
			return (int) $row['id'];
		}

		// 2b. Link-Match mit normalisierter URL
		if ($normalized !== $url) {
			$sth->execute([$normalized, $owner_uid]);
			if ($row = $sth->fetch()) {
				return (int) $row['id'];
			}
		}

		return null;
	}

	/**
	 * Prüft ob ein Artikel das Später-Lesen-Label hat.
	 */
	private function is_read_later(int $ref_id, int $owner_uid): bool {
		$label_id = Labels::find_id(self::READ_LATER_LABEL, $owner_uid);
		if (!$label_id) return false;

		$sth = $this->pdo->prepare("SELECT article_id FROM ttrss_user_labels2
			WHERE label_id = ? AND article_id = ? LIMIT 1");
		$sth->execute([$label_id, $ref_id]);

		return $sth->fetch() !== false;
	}

	// ─── Health-Check ───────────────────────────────────────────

	/**
	 * Health-Check-Endpunkt.
	 */
	function check(): void {
		$this->send_api_headers();
		if ($this->handle_preflight()) return;

		print json_encode([
			'status' => 'ok',
			'version' => '2.0'
		]);
	}

	// ─── Artikel speichern ──────────────────────────────────────

	/**
	 * Artikel über API speichern.
	 */
	function save_article(): void {
		$this->send_api_headers();
		if ($this->handle_preflight()) return;

		$input = $this->read_input();
		$owner_uid = $this->authenticate($input);
		if (!$owner_uid) return;

		$url = strip_tags($input['url'] ?? '');
		$title = strip_tags($input['title'] ?? '');
		$content = $input['content'] ?? '';

		if (empty($url)) {
			print json_encode(['status' => 'error', 'message' => 'URL ist erforderlich.']);
			return;
		}

		if (empty($title)) {
			$title = $url;
		}

		try {
			Article::_create_published_article($title, $url, $content, '', $owner_uid);

			// Ref-ID für die Antwort ermitteln
			$ref_id = $this->find_article_by_url($url, $owner_uid);

			print json_encode([
				'status' => 'ok',
				'ref_id' => $ref_id
			]);
		} catch (Exception $e) {
			Debug::log("browser_extension: Fehler beim Speichern: " . $e->getMessage(), Debug::LOG_VERBOSE);
			print json_encode(['status' => 'error', 'message' => 'Fehler beim Speichern des Artikels.']);
		}
	}

	// ─── Status-Abfrage ─────────────────────────────────────────

	/**
	 * Prüft ob eine URL gespeichert ist und gibt Metadaten zurück.
	 */
	function get_status(): void {
		$this->send_api_headers();
		if ($this->handle_preflight()) return;

		$input = $this->read_input();
		$owner_uid = $this->authenticate($input);
		if (!$owner_uid) return;

		$url = strip_tags($input['url'] ?? '');
		if (empty($url)) {
			print json_encode(['status' => 'error', 'message' => 'URL ist erforderlich.']);
			return;
		}

		$ref_id = $this->find_article_by_url($url, $owner_uid);

		if (!$ref_id) {
			print json_encode([
				'status' => 'ok',
				'saved' => false,
				'ref_id' => null,
				'labels' => [],
				'annotation_count' => 0,
				'read_later' => false,
				'page_note' => ''
			]);
			return;
		}

		// Labels für den Artikel holen
		$labels_raw = Article::_get_labels($ref_id, $owner_uid);
		$labels = [];
		foreach ($labels_raw as $l) {
			$labels[] = [
				'id' => (int) $l[0],
				'caption' => $l[1],
				'fg_color' => $l[2],
				'bg_color' => $l[3]
			];
		}

		// Annotationen zählen (ohne Seitennotizen)
		$sth = $this->pdo->prepare("SELECT COUNT(*) AS cnt FROM ttrss_plugin_annotations
			WHERE ref_id = ? AND owner_uid = ? AND highlighted_text != ''");
		$sth->execute([$ref_id, $owner_uid]);
		$annotation_count = (int) ($sth->fetch()['cnt'] ?? 0);

		// Seitennotiz holen
		$sth = $this->pdo->prepare("SELECT note FROM ttrss_plugin_annotations
			WHERE ref_id = ? AND owner_uid = ? AND highlighted_text = ''
			AND selector_path = ? LIMIT 1");
		$sth->execute([$ref_id, $owner_uid, '{"type":"page_note"}']);
		$page_note_row = $sth->fetch();
		$page_note = $page_note_row ? ($page_note_row['note'] ?? '') : '';

		// Später-Lesen-Status
		$read_later = $this->is_read_later($ref_id, $owner_uid);

		print json_encode([
			'status' => 'ok',
			'saved' => true,
			'ref_id' => $ref_id,
			'labels' => $labels,
			'annotation_count' => $annotation_count,
			'read_later' => $read_later,
			'page_note' => $page_note
		]);
	}

	// ─── Annotationen ───────────────────────────────────────────

	/**
	 * Alle Annotationen für eine URL abrufen.
	 */
	function get_annotations_for_url(): void {
		$this->send_api_headers();
		if ($this->handle_preflight()) return;

		$input = $this->read_input();
		$owner_uid = $this->authenticate($input);
		if (!$owner_uid) return;

		$url = strip_tags($input['url'] ?? '');
		if (empty($url)) {
			print json_encode(['status' => 'error', 'message' => 'URL ist erforderlich.']);
			return;
		}

		$ref_id = $this->find_article_by_url($url, $owner_uid);

		if (!$ref_id) {
			print json_encode([]);
			return;
		}

		$sth = $this->pdo->prepare("SELECT id, selector_path, start_offset, end_offset,
			highlighted_text, note, color FROM ttrss_plugin_annotations
			WHERE ref_id = ? AND owner_uid = ? AND highlighted_text != ''
			ORDER BY id");
		$sth->execute([$ref_id, $owner_uid]);

		$result = [];
		while ($row = $sth->fetch(PDO::FETCH_ASSOC)) {
			$result[] = $row;
		}

		print json_encode($result);
	}

	/**
	 * Annotation für eine URL speichern.
	 */
	function save_annotation_for_url(): void {
		$this->send_api_headers();
		if ($this->handle_preflight()) return;

		$input = $this->read_input();
		$owner_uid = $this->authenticate($input);
		if (!$owner_uid) return;

		$url = strip_tags($input['url'] ?? '');
		$highlighted_text = strip_tags($input['highlighted_text'] ?? '');
		$note = strip_tags($input['note'] ?? '');
		$color_raw = $input['color'] ?? '#fff3cd';
		$color = preg_match('/^#[0-9a-fA-F]{3,8}$/', $color_raw) ? $color_raw : '#fff3cd';
		$selector_path_raw = $input['selector_path'] ?? '';
		// Nur gültiges JSON akzeptieren
		$selector_path = '';
		if (!empty($selector_path_raw)) {
			$decoded = json_decode($selector_path_raw, true);
			if (is_array($decoded)) {
				$selector_path = json_encode($decoded);
			}
		}
		$start_offset = (int)($input['start_offset'] ?? 0);
		$end_offset = (int)($input['end_offset'] ?? 0);

		if (empty($url)) {
			print json_encode(['status' => 'error', 'message' => 'URL ist erforderlich.']);
			return;
		}

		if (empty($highlighted_text)) {
			print json_encode(['status' => 'error', 'message' => 'Kein Text zum Markieren angegeben.']);
			return;
		}

		$ref_id = $this->find_article_by_url($url, $owner_uid);

		if (!$ref_id) {
			print json_encode(['status' => 'error', 'message' => 'Artikel nicht gespeichert. Bitte zuerst die Seite speichern.']);
			return;
		}

		$sth = $this->pdo->prepare("INSERT INTO ttrss_plugin_annotations
			(ref_id, owner_uid, selector_path, start_offset, end_offset, highlighted_text, note, color)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
		$sth->execute([$ref_id, $owner_uid, $selector_path,
			$start_offset, $end_offset, $highlighted_text, $note, $color]);

		$id = $this->pdo->lastInsertId();

		print json_encode(['id' => $id, 'status' => 'ok']);
	}

	/**
	 * Annotation aktualisieren (Notiz/Farbe).
	 */
	function update_annotation_ext(): void {
		$this->send_api_headers();
		if ($this->handle_preflight()) return;

		$input = $this->read_input();
		$owner_uid = $this->authenticate($input);
		if (!$owner_uid) return;

		$id = (int)($input['id'] ?? 0);
		$note = strip_tags($input['note'] ?? '');
		$color_raw = $input['color'] ?? '#fff3cd';
		$color = preg_match('/^#[0-9a-fA-F]{3,8}$/', $color_raw) ? $color_raw : '#fff3cd';

		if (!$id) {
			print json_encode(['status' => 'error', 'message' => 'Fehlende ID.']);
			return;
		}

		$sth = $this->pdo->prepare("UPDATE ttrss_plugin_annotations
			SET note = ?, color = ? WHERE id = ? AND owner_uid = ?");
		$sth->execute([$note, $color, $id, $owner_uid]);

		print json_encode(['status' => 'ok']);
	}

	/**
	 * Annotation löschen.
	 */
	function delete_annotation_ext(): void {
		$this->send_api_headers();
		if ($this->handle_preflight()) return;

		$input = $this->read_input();
		$owner_uid = $this->authenticate($input);
		if (!$owner_uid) return;

		$id = (int)($input['id'] ?? 0);

		if (!$id) {
			print json_encode(['status' => 'error', 'message' => 'Fehlende ID.']);
			return;
		}

		$sth = $this->pdo->prepare("DELETE FROM ttrss_plugin_annotations
			WHERE id = ? AND owner_uid = ?");
		$sth->execute([$id, $owner_uid]);

		print json_encode(['status' => 'ok']);
	}

	// ─── Labels ─────────────────────────────────────────────────

	/**
	 * Alle Labels des Benutzers abrufen.
	 */
	function get_labels(): void {
		$this->send_api_headers();
		if ($this->handle_preflight()) return;

		$input = $this->read_input();
		$owner_uid = $this->authenticate($input);
		if (!$owner_uid) return;

		$labels = Labels::get_all($owner_uid);

		print json_encode($labels);
	}

	/**
	 * Labels für eine URL setzen (Diff: hinzufügen/entfernen).
	 */
	function set_labels_for_url(): void {
		$this->send_api_headers();
		if ($this->handle_preflight()) return;

		$input = $this->read_input();
		$owner_uid = $this->authenticate($input);
		if (!$owner_uid) return;

		$url = strip_tags($input['url'] ?? '');
		$label_ids = $input['label_ids'] ?? [];

		if (empty($url)) {
			print json_encode(['status' => 'error', 'message' => 'URL ist erforderlich.']);
			return;
		}

		$ref_id = $this->find_article_by_url($url, $owner_uid);

		if (!$ref_id) {
			print json_encode(['status' => 'error', 'message' => 'Artikel nicht gefunden.']);
			return;
		}

		if (!is_array($label_ids)) {
			$label_ids = [];
		}
		$label_ids = array_map('intval', $label_ids);

		// Alle verfügbaren Labels holen
		$all_labels = Labels::get_all($owner_uid);

		// Aktuelle Labels des Artikels holen
		$current = Article::_get_labels($ref_id, $owner_uid);
		$current_ids = array_map(function ($l) { return (int) $l[0]; }, $current);

		// Diff berechnen und anwenden
		foreach ($all_labels as $label) {
			$lid = (int) $label['id'];
			$caption = $label['caption'];
			$should_have = in_array($lid, $label_ids);
			$has = in_array($lid, $current_ids);

			if ($should_have && !$has) {
				Labels::add_article($ref_id, $caption, $owner_uid);
			} elseif (!$should_have && $has) {
				Labels::remove_article($ref_id, $caption, $owner_uid);
			}
		}

		print json_encode(['status' => 'ok']);
	}

	// ─── Später lesen ───────────────────────────────────────────

	/**
	 * Später-Lesen-Status für eine URL umschalten.
	 */
	function toggle_read_later(): void {
		$this->send_api_headers();
		if ($this->handle_preflight()) return;

		$input = $this->read_input();
		$owner_uid = $this->authenticate($input);
		if (!$owner_uid) return;

		$url = strip_tags($input['url'] ?? '');

		if (empty($url)) {
			print json_encode(['status' => 'error', 'message' => 'URL ist erforderlich.']);
			return;
		}

		$ref_id = $this->find_article_by_url($url, $owner_uid);

		if (!$ref_id) {
			print json_encode(['status' => 'error', 'message' => 'Artikel nicht gefunden.']);
			return;
		}

		// Label sicherstellen
		$label_id = Labels::find_id(self::READ_LATER_LABEL, $owner_uid);
		if (!$label_id) {
			Labels::create(self::READ_LATER_LABEL, '#ffffff', '#2196f3', $owner_uid);
		}

		if ($this->is_read_later($ref_id, $owner_uid)) {
			Labels::remove_article($ref_id, self::READ_LATER_LABEL, $owner_uid);
			$is_saved = false;
		} else {
			Labels::add_article($ref_id, self::READ_LATER_LABEL, $owner_uid);
			$is_saved = true;
		}

		print json_encode(['status' => 'ok', 'read_later' => $is_saved]);
	}

	// ─── Seitennotizen ──────────────────────────────────────────

	/**
	 * Seitennotiz für eine URL abrufen.
	 */
	function get_page_note(): void {
		$this->send_api_headers();
		if ($this->handle_preflight()) return;

		$input = $this->read_input();
		$owner_uid = $this->authenticate($input);
		if (!$owner_uid) return;

		$url = strip_tags($input['url'] ?? '');

		if (empty($url)) {
			print json_encode(['status' => 'error', 'message' => 'URL ist erforderlich.']);
			return;
		}

		$ref_id = $this->find_article_by_url($url, $owner_uid);

		if (!$ref_id) {
			print json_encode(['status' => 'ok', 'note' => '']);
			return;
		}

		$sth = $this->pdo->prepare("SELECT note FROM ttrss_plugin_annotations
			WHERE ref_id = ? AND owner_uid = ? AND highlighted_text = ''
			AND selector_path = ? LIMIT 1");
		$sth->execute([$ref_id, $owner_uid, '{"type":"page_note"}']);
		$row = $sth->fetch();

		print json_encode(['status' => 'ok', 'note' => $row ? ($row['note'] ?? '') : '']);
	}

	/**
	 * Seitennotiz für eine URL setzen (erstellen oder aktualisieren).
	 */
	function set_page_note(): void {
		$this->send_api_headers();
		if ($this->handle_preflight()) return;

		$input = $this->read_input();
		$owner_uid = $this->authenticate($input);
		if (!$owner_uid) return;

		$url = strip_tags($input['url'] ?? '');
		$note = strip_tags($input['note'] ?? '');

		if (empty($url)) {
			print json_encode(['status' => 'error', 'message' => 'URL ist erforderlich.']);
			return;
		}

		$ref_id = $this->find_article_by_url($url, $owner_uid);

		if (!$ref_id) {
			print json_encode(['status' => 'error', 'message' => 'Artikel nicht gefunden.']);
			return;
		}

		$page_note_marker = '{"type":"page_note"}';

		// Bestehende Seitennotiz suchen
		$sth = $this->pdo->prepare("SELECT id FROM ttrss_plugin_annotations
			WHERE ref_id = ? AND owner_uid = ? AND highlighted_text = ''
			AND selector_path = ? LIMIT 1");
		$sth->execute([$ref_id, $owner_uid, $page_note_marker]);
		$existing = $sth->fetch();

		if ($existing) {
			if (empty($note)) {
				// Leere Notiz → löschen
				$sth = $this->pdo->prepare("DELETE FROM ttrss_plugin_annotations
					WHERE id = ? AND owner_uid = ?");
				$sth->execute([$existing['id'], $owner_uid]);
			} else {
				// Aktualisieren
				$sth = $this->pdo->prepare("UPDATE ttrss_plugin_annotations
					SET note = ? WHERE id = ? AND owner_uid = ?");
				$sth->execute([$note, $existing['id'], $owner_uid]);
			}
		} elseif (!empty($note)) {
			// Neue Seitennotiz erstellen
			$sth = $this->pdo->prepare("INSERT INTO ttrss_plugin_annotations
				(ref_id, owner_uid, selector_path, start_offset, end_offset, highlighted_text, note, color)
				VALUES (?, ?, ?, 0, 0, '', ?, '#fff3cd')");
			$sth->execute([$ref_id, $owner_uid, $page_note_marker, $note]);
		}

		print json_encode(['status' => 'ok']);
	}

	/**
	 * Validiert den API-Schlüssel und gibt die owner_uid zurück.
	 * @param string $api_key
	 * @return int|false
	 */
	private function validate_api_key(string $api_key) {
		// Alle Benutzer-IDs durchsuchen, die dieses Plugin verwenden
		$escaped_key = str_replace(['%', '_'], ['\%', '\_'], $api_key);
		$sth = $this->pdo->prepare("SELECT owner_uid FROM ttrss_plugin_storage
			WHERE name = 'browser_extension' AND content LIKE ? ESCAPE '\\'");
		$sth->execute(['%' . $escaped_key . '%']);

		while ($row = $sth->fetch(PDO::FETCH_ASSOC)) {
			$uid = (int) $row['owner_uid'];
			// Plugin-Storage für den Benutzer laden und Key prüfen
			$sth2 = $this->pdo->prepare("SELECT content FROM ttrss_plugin_storage
				WHERE name = 'browser_extension' AND owner_uid = ?");
			$sth2->execute([$uid]);
			$storage_row = $sth2->fetch(PDO::FETCH_ASSOC);

			if ($storage_row) {
				$storage = json_decode($storage_row['content'], true);
				if (is_array($storage) && hash_equals($storage['api_key'] ?? '', $api_key)) {
					return $uid;
				}
			}
		}

		return false;
	}

	/**
	 * Neuen API-Schlüssel generieren (AJAX-Handler).
	 */
	function generate_key(): void {
		$api_key = bin2hex(random_bytes(32));
		$this->host->set($this, 'api_key', $api_key);

		print json_encode([
			'status' => 'ok',
			'api_key' => $api_key
		]);
	}

	/**
	 * Aktuellen API-Schlüssel abrufen (AJAX-Handler).
	 */
	function get_key(): void {
		$api_key = $this->host->get($this, 'api_key', '');

		print json_encode([
			'api_key' => $api_key
		]);
	}

	/**
	 * Einstellungs-Tab.
	 */
	function hook_prefs_tab($args) {
		if ($args != "prefPrefs") return;

		$api_key = $this->host->get($this, 'api_key', '');
		$base_url = get_self_url_prefix();
		$endpoint = htmlspecialchars($base_url . "/public.php?op=pluginhandler&plugin=browser_extension&pmethod=save_article");

		$bookmarklet_js = "javascript:void(fetch('" . $base_url . "/public.php?op=pluginhandler&plugin=browser_extension&pmethod=save_article',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({url:location.href,title:document.title,api_key:'" . htmlspecialchars($api_key) . "'})}).then(r=>r.json()).then(d=>alert(d.status==='ok'?'Gespeichert!':'Fehler: '+d.message)).catch(e=>alert('Fehler: '+e)))";
		?>
		<div dojoType="dijit.layout.AccordionPane"
			title="<i class='material-icons'>extension</i> <?= __('Browser-Erweiterung') ?>">

			<h3><?= __('API-Schlüssel') ?></h3>

			<div id="be-api-key-section">
				<?php if ($api_key): ?>
					<fieldset>
						<label><?= __('Aktueller Schlüssel:') ?></label>
						<code id="be-current-key" style="user-select: all; padding: 4px 8px; background: var(--bg-color-secondary, #f5f5f5); border-radius: 4px;"><?= htmlspecialchars($api_key) ?></code>
					</fieldset>
				<?php else: ?>
					<p><?= __('Kein API-Schlüssel vorhanden.') ?></p>
				<?php endif; ?>

				<button dojoType="dijit.form.Button" type="button"
					onclick="Plugins.Browser_Extension.generateKey()">
					<i class="material-icons">vpn_key</i> <?= $api_key ? __('Schlüssel erneuern') : __('Schlüssel generieren') ?>
				</button>
			</div>

			<hr/>

			<h3><?= __('API-Endpunkt') ?></h3>
			<fieldset>
				<label><?= __('URL:') ?></label>
				<code style="user-select: all; word-break: break-all; padding: 4px 8px; background: var(--bg-color-secondary, #f5f5f5); border-radius: 4px;"><?= $endpoint ?></code>
			</fieldset>

			<hr/>

			<?php if ($api_key): ?>
			<h3><?= __('Bookmarklet') ?></h3>
			<p><?= __('Ziehe den folgenden Link in deine Lesezeichen-Leiste:') ?></p>
			<label class="dijitButton">
				<a href="<?= htmlspecialchars($bookmarklet_js) ?>"><?= __('In TT-RSS speichern') ?></a>
			</label>
			<hr/>
			<?php endif; ?>

			<h3><?= __('Anleitung für Browser-Erweiterungen') ?></h3>
			<div style="padding: 8px; background: var(--bg-color-secondary, #f5f5f5); border-radius: 4px;">
				<p><?= __('Sende einen POST-Request an den API-Endpunkt mit folgendem JSON-Body:') ?></p>
				<pre style="margin: 8px 0; padding: 8px; background: var(--bg-color, #fff); border-radius: 4px;">{
  "url": "https://example.com/article",
  "title": "Artikeltitel",
  "content": "Optionaler Inhalt (HTML)",
  "api_key": "DEIN_API_SCHLÜSSEL"
}</pre>
				<p><?= __('Antwort bei Erfolg:') ?> <code>{"status": "ok"}</code></p>
				<p><?= __('Antwort bei Fehler:') ?> <code>{"status": "error", "message": "..."}</code></p>
			</div>
		</div>

		<script type="text/javascript">
			if (typeof Plugins === "undefined") window.Plugins = {};
			Plugins.Browser_Extension = {
				generateKey: function() {
					if (!confirm("<?= __('Neuen API-Schlüssel generieren? Der alte wird ungültig.') ?>")) return;

					xhr.json("backend.php", {
						op: "pluginhandler",
						plugin: "browser_extension",
						method: "generate_key"
					}, function(reply) {
						if (reply.status === "ok") {
							Notify.info("<?= __('Neuer Schlüssel generiert. Seite wird neu geladen...') ?>");
							window.setTimeout(function() { location.reload(); }, 1000);
						} else {
							Notify.error("<?= __('Fehler beim Generieren des Schlüssels.') ?>");
						}
					});
				}
			};
		</script>

		<?php
	}

	function get_css() {
		return file_get_contents(__DIR__ . "/browser_extension.css");
	}

	function api_version() {
		return 2;
	}
}
