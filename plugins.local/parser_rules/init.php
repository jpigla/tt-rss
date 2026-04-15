<?php
/**
 * Parser Rules -- Domain-spezifische Extraktionsregeln aus Reader-Selektion.
 *
 * Registriert sich auf HOOK_GET_FULL_TEXT mit Priority 25 (vor af_fulltext = 50).
 * Fällt bei fehlendem Match auf af_fulltext zurück.
 */
class Parser_Rules extends Plugin {

	/** Maximale Anzahl aktiver Regeln pro Benutzer */
	private const MAX_RULES_PER_USER = 200;

	/** Maximale Länge eines XPath/CSS-Selektors */
	private const MAX_SELECTOR_LENGTH = 500;

	/** Erlaubte XPath-Achsen und Funktionen (Allowlist) */
	private const XPATH_ALLOWED_PATTERN = '/^[\/\.\*\[\]@a-zA-Z0-9_\-\s\(\)=\'",:!|]+$/';

	/** @var PluginHost $host */
	private $host;

	function about(): array {
		return [1.0,
			"Domain-spezifische Extraktionsregeln aus Leser-Selektion lernen",
			"tt-ss",
			false];
	}

	function init($host): void {
		$this->host = $host;

		$host->add_hook($host::HOOK_GET_FULL_TEXT, $this, 25);
		$host->add_hook($host::HOOK_PREFS_TAB, $this);

		$m = new Db_Migrations();
		$m->initialize_for_plugin($this);
		$m->migrate();
	}

	/**
	 * Prefs-Tab-JS: AMD-Modul für Hauptanwendung (index.php / prefs.php).
	 */
	function get_js(): string {
		return file_get_contents(__DIR__ . '/parser_rules_prefs.js');
	}

	/**
	 * Reader-JS: IIFE ohne Dojo-Abhängigkeiten, eingebettet von reader.php.
	 */
	function get_reader_js(): string {
		return file_get_contents(__DIR__ . '/parser_rules.js');
	}

	function get_css(): string {
		return file_get_contents(__DIR__ . '/parser_rules.css');
	}

	/**
	 * HOOK_GET_FULL_TEXT (Priority 25) -- versucht Domain-Regeln vor af_fulltext.
	 * @param string $url
	 * @return string|false
	 */
	function hook_get_full_text(string $url) {
		$domain = $this->normalize_domain($url);
		if (!$domain) return false;

		$owner_uid = $_SESSION['uid'] ?? 0;
		if (!$owner_uid) return false;

		$sth = $this->pdo->prepare("SELECT id, rule_type, selector_xpath, confidence,
				hit_count, miss_count
			FROM ttrss_plugin_parser_rules
			WHERE owner_uid = ? AND domain = ? AND is_active = TRUE
			ORDER BY confidence DESC");
		$sth->execute([$owner_uid, $domain]);

		$rules = $sth->fetchAll(PDO::FETCH_ASSOC);
		if (!$rules) return false;

		// Seite laden
		$html = UrlHelper::fetch([
			'url' => $url,
			'timeout' => 15,
			'followlocation' => true
		]);
		if (!$html) return false;

		$doc = new DOMDocument();
		$prev = libxml_use_internal_errors(true);
		$doc->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
		libxml_use_internal_errors($prev);
		$xpath = new DOMXPath($doc);

		// Exclude-Regeln zuerst: Noise-Elemente entfernen
		foreach ($rules as $rule) {
			if ($rule['rule_type'] !== 'exclude') continue;
			$eff_conf = $this->effective_confidence($rule);
			if ($eff_conf < 0.3) continue;

			$this->apply_exclude_rule($xpath, $doc, $rule);
		}

		// Include-Regeln: höchste Confidence zuerst
		foreach ($rules as $rule) {
			if ($rule['rule_type'] !== 'include') continue;
			$eff_conf = $this->effective_confidence($rule);
			if ($eff_conf < 0.3) continue;

			$content = $this->apply_include_rule($xpath, $doc, $rule, $html);
			if ($content !== false) return $content;
		}

		return false;
	}

	/**
	 * Berechnet effektive Confidence: base × (hits+1) / (hits+misses+1)
	 * @param array $rule
	 * @return float
	 */
	private function effective_confidence(array $rule): float {
		$hits = (int)$rule['hit_count'];
		$misses = (int)$rule['miss_count'];
		return (float)$rule['confidence'] * ($hits + 1) / ($hits + $misses + 1);
	}

	/**
	 * Exclude-Regel anwenden: Elemente aus DOM entfernen.
	 */
	private function apply_exclude_rule(DOMXPath $xpath, DOMDocument $doc, array $rule): void {
		if (empty($rule['selector_xpath'])) return;
		if (!$this->is_safe_xpath($rule['selector_xpath'])) return;
		$nodes = @$xpath->query($rule['selector_xpath']);
		if (!$nodes) return;

		$to_remove = [];
		foreach ($nodes as $node) {
			$to_remove[] = $node;
		}
		foreach ($to_remove as $node) {
			if ($node->parentNode) {
				$node->parentNode->removeChild($node);
			}
		}

		$this->record_hit((int)$rule['id']);
	}

	/**
	 * Include-Regel anwenden: Content extrahieren.
	 * @return string|false
	 */
	private function apply_include_rule(DOMXPath $xpath, DOMDocument $doc, array $rule, string $full_html) {
		if (empty($rule['selector_xpath']) || !$this->is_safe_xpath($rule['selector_xpath'])) {
			$this->record_miss((int)$rule['id']);
			return false;
		}

		$nodes = @$xpath->query($rule['selector_xpath']);
		if (!$nodes || $nodes->length === 0) {
			$this->record_miss((int)$rule['id']);
			return false;
		}

		// Längsten Match nehmen
		$best = null;
		$best_len = 0;
		foreach ($nodes as $node) {
			$len = mb_strlen(trim($node->textContent));
			if ($len > $best_len) {
				$best = $node;
				$best_len = $len;
			}
		}

		if (!$best) {
			$this->record_miss((int)$rule['id']);
			return false;
		}

		$content = $doc->saveHTML($best);
		$text_len = mb_strlen(strip_tags($content));

		// Sanity-Checks
		if ($text_len < 200) {
			$this->record_miss((int)$rule['id']);
			return false;
		}

		$body_len = mb_strlen(strip_tags($full_html));
		if ($body_len > 0 && $text_len > $body_len * 0.95) {
			$this->record_miss((int)$rule['id']);
			return false;
		}

		$this->record_hit((int)$rule['id']);
		return $content;
	}

	private function record_hit(int $rule_id): void {
		$owner_uid = $_SESSION['uid'] ?? 0;
		$sth = $this->pdo->prepare("UPDATE ttrss_plugin_parser_rules
			SET hit_count = hit_count + 1, last_hit_at = NOW(), updated_at = NOW()
			WHERE id = ? AND owner_uid = ?");
		$sth->execute([$rule_id, $owner_uid]);
	}

	private function record_miss(int $rule_id): void {
		$owner_uid = $_SESSION['uid'] ?? 0;
		$sth = $this->pdo->prepare("UPDATE ttrss_plugin_parser_rules
			SET miss_count = miss_count + 1, updated_at = NOW()
			WHERE id = ? AND owner_uid = ?");
		$sth->execute([$rule_id, $owner_uid]);

		// Auto-Deaktivierung: miss_count > 3 AND miss_count > hit_count
		$sth = $this->pdo->prepare("UPDATE ttrss_plugin_parser_rules
			SET is_active = FALSE
			WHERE id = ? AND owner_uid = ? AND miss_count > 3 AND miss_count > hit_count");
		$sth->execute([$rule_id, $owner_uid]);
	}

	/**
	 * CSRF für reine Leseabfragen überspringen (has_rules via GET).
	 * Schreibende Methoden (learn_rule, toggle_rule, delete_rule, etc.)
	 * erfordern weiterhin einen gültigen CSRF-Token.
	 */
	function csrf_ignore(string $method): bool {
		return in_array($method, ['has_rules', 'get_rules'], true);
	}

	/**
	 * AJAX: Regel aus Textauswahl lernen (mit LLM-Optimierung).
	 */
	function learn_rule(): void {
		$article_url = clean($_POST['article_url'] ?? '');
		$domain = clean($_POST['domain'] ?? '');
		$selected_text = clean($_POST['selected_text'] ?? '');
		$parent_chain = clean($_POST['parent_chain'] ?? '');
		// surrounding_html: Roh-HTML wird nur als LLM-Kontext verwendet,
		// nie an den Browser zurückgegeben. strip_tags() + Längenbegrenzung.
		$surrounding_html = mb_substr(strip_tags($_POST['surrounding_html'] ?? ''), 0, 4000);
		$rule_type = clean($_POST['rule_type'] ?? 'include');

		if (!$article_url || !$domain || !$selected_text) {
			print json_encode(['error' => 'Pflichtfelder fehlen']);
			return;
		}

		$owner_uid = $_SESSION['uid'] ?? 0;
		if (!$owner_uid) {
			print json_encode(['error' => 'Nicht angemeldet']);
			return;
		}

		$domain = $this->normalize_domain_str($domain);

		if (!in_array($rule_type, ['include', 'exclude'])) {
			$rule_type = 'include';
		}

		// DoS-Schutz: Maximale Regelanzahl pro Benutzer
		$count_sth = $this->pdo->prepare("SELECT COUNT(*) AS cnt
			FROM ttrss_plugin_parser_rules WHERE owner_uid = ?");
		$count_sth->execute([$owner_uid]);
		$rule_count = (int)$count_sth->fetch(PDO::FETCH_ASSOC)['cnt'];
		if ($rule_count >= self::MAX_RULES_PER_USER) {
			print json_encode(['error' => 'Maximale Regelanzahl erreicht (' . self::MAX_RULES_PER_USER . '). Bitte lösche ungenutzte Regeln.']);
			return;
		}

		// Originalseite laden für LLM-Kontext
		$page_html = UrlHelper::fetch([
			'url' => $article_url,
			'timeout' => 15,
			'followlocation' => true
		]);

		// HTML-Snippet begrenzen (max 4000 Zeichen für LLM-Kontext).
		// surrounding_html ist bereits strip_tags()-bereinigt (s.o.).
		// page_snippet enthält HTML-Struktur aus dem Server-Fetch (vertrauenswürdig
		// für LLM-Kontext, wird nie an den Browser zurückgegeben).
		$html_context = $surrounding_html;
		if ($page_html) {
			// Zusätzlichen Kontext aus der Originalseite extrahieren
			$page_snippet = $this->extract_html_around_text($page_html, $selected_text, 3000);
			if ($page_snippet) {
				$html_context = $page_snippet;
			}
		}

		// LLM-Aufruf
		$llm_result = $this->ask_llm_for_selector($selected_text, $parent_chain, $html_context, $rule_type, $domain);

		if (!$llm_result) {
			// Fallback ohne LLM: Parent-Chain direkt als XPath verwenden
			$llm_result = $this->fallback_selector($parent_chain, $rule_type);
		}

		// XPath-Selektor-Validierung: Länge und erlaubte Zeichen prüfen
		if (!$this->is_safe_xpath($llm_result['xpath_selector'])) {
			print json_encode(['error' => 'Ungültiger XPath-Selektor (unerlaubte Zeichen oder zu lang)']);
			return;
		}
		if (mb_strlen($llm_result['css_selector']) > self::MAX_SELECTOR_LENGTH) {
			print json_encode(['error' => 'CSS-Selektor zu lang']);
			return;
		}

		// Server-seitige Validierung gegen echtes HTML
		if ($page_html && $llm_result['xpath_selector']) {
			$validation = $this->validate_rule($page_html, $llm_result['xpath_selector'], $selected_text);
			if (!$validation['valid']) {
				print json_encode([
					'error' => 'Regel-Validierung fehlgeschlagen: ' . $validation['reason'],
					'selector' => $llm_result,
				]);
				return;
			}
		}

		// In DB speichern — RETURNING id statt lastInsertId() (PostgreSQL-kompatibel)
		$sth = $this->pdo->prepare("INSERT INTO ttrss_plugin_parser_rules
			(owner_uid, domain, rule_type, selector_css, selector_xpath, sample_text,
			 sample_url, sample_html, confidence, llm_reasoning)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
			RETURNING id");
		$sth->execute([
			$owner_uid,
			$domain,
			$rule_type,
			mb_substr($llm_result['css_selector'], 0, self::MAX_SELECTOR_LENGTH),
			mb_substr($llm_result['xpath_selector'], 0, self::MAX_SELECTOR_LENGTH),
			mb_substr($selected_text, 0, 500),
			$article_url,
			mb_substr(strip_tags($html_context), 0, 5000),
			$llm_result['confidence'],
			mb_substr($llm_result['reasoning'], 0, 2000),
		]);

		$row = $sth->fetch(PDO::FETCH_ASSOC);
		$rule_id = (int)($row['id'] ?? 0);

		print json_encode([
			'success' => true,
			'rule_id' => (int)$rule_id,
			'css_selector' => $llm_result['css_selector'],
			'xpath_selector' => $llm_result['xpath_selector'],
			'confidence' => $llm_result['confidence'],
			'reasoning' => $llm_result['reasoning'],
			'ai_used' => $llm_result['ai_used'] ?? true,
		]);
	}

	/**
	 * LLM aufrufen um robusten Selektor zu generieren.
	 * @return array{css_selector: string, xpath_selector: string, confidence: float, reasoning: string, ai_used: bool}|null
	 */
	private function ask_llm_for_selector(string $selected_text, string $parent_chain, string $html_context, string $rule_type, string $domain): ?array {
		if (!class_exists('Ai_Core')) return null;

		$type_desc = $rule_type === 'include'
			? 'den Hauptinhalt-Container (Artikeltext)'
			: 'einen störenden Bereich (z.B. Werbung, Navigation, Sidebar)';

		$system_prompt = <<<PROMPT
Du bist ein HTML-Content-Extraction-Spezialist. Der Nutzer hat Text auf einer Webseite markiert, der {$type_desc} repräsentiert. Leite einen robusten CSS-Selektor und XPath-Ausdruck ab, der diesen Bereich auf Seiten der Domain "{$domain}" zuverlässig findet.

Regeln:
- Bevorzuge semantische HTML-Tags (article, main, section) und stabile Klassennamen
- Vermeide dynamische IDs (z.B. mit Hashes/Zahlen), Inline-Styles, tief verschachtelte Pfade
- Der Selektor soll den CONTAINER treffen, nicht einzelne Absätze oder Spans
- Berücksichtige, dass die Domain verschiedene Templates haben kann (Artikel vs. Startseite)
- Bewerte deine Konfidenz 0.0-1.0 basierend auf Stabilität und Generalisierbarkeit
- Bei niedrigen Konfidenzwerten (<0.5) erkläre warum der Selektor fragil sein könnte

Antworte AUSSCHLIESSLICH als JSON (kein Markdown, keine Codeblöcke):
{"css_selector": "...", "xpath_selector": "...", "confidence": 0.85, "reasoning": "..."}
PROMPT;

		$user_message = <<<MSG
Domain: {$domain}
Regel-Typ: {$rule_type}
Markierter Text (Auszug): {$selected_text}

DOM-Pfad der Selektion: {$parent_chain}

HTML-Kontext um die Selektion:
{$html_context}
MSG;

		$response = Ai_Core::complete($system_prompt, $user_message, 512);
		if (!$response) return null;

		// JSON extrahieren (LLM könnte Markdown-Codeblöcke liefern)
		$json_str = $response;
		if (preg_match('/\{[^{}]*"css_selector"[^{}]*\}/s', $response, $m)) {
			$json_str = $m[0];
		}

		$data = json_decode($json_str, true);
		if (!$data || !isset($data['css_selector']) || !isset($data['xpath_selector'])) {
			return null;
		}

		return [
			'css_selector' => (string)$data['css_selector'],
			'xpath_selector' => (string)$data['xpath_selector'],
			'confidence' => max(0.0, min(1.0, (float)($data['confidence'] ?? 0.5))),
			'reasoning' => (string)($data['reasoning'] ?? ''),
			'ai_used' => true,
		];
	}

	/**
	 * Fallback: Parent-Chain als XPath-Selektor verwenden (ohne LLM).
	 * @return array{css_selector: string, xpath_selector: string, confidence: float, reasoning: string, ai_used: bool}
	 */
	private function fallback_selector(string $parent_chain, string $rule_type): array {
		// parent_chain ist z.B. "article.post-content > div.entry-body > p"
		// Wir nehmen das oberste semantische Element
		$parts = explode(' > ', $parent_chain);
		$css = $parts[0] ?? 'article';

		// CSS zu XPath konvertieren (einfache Fälle)
		$xpath = $this->css_to_xpath_simple($css);

		return [
			'css_selector' => $css,
			'xpath_selector' => $xpath,
			'confidence' => 0.4,
			'reasoning' => 'Ohne KI-Validierung erstellt (Fallback). Basiert auf dem DOM-Pfad der Selektion.',
			'ai_used' => false,
		];
	}

	/**
	 * Einfache CSS→XPath-Konvertierung für Basisfälle.
	 */
	private function css_to_xpath_simple(string $css): string {
		$css = trim($css);

		// tag.class → //tag[contains(@class, "class")]
		if (preg_match('/^([a-z]+)\.([a-z0-9_-]+)$/i', $css, $m)) {
			return '//' . $m[1] . '[contains(@class, "' . $m[2] . '")]';
		}

		// tag#id → //tag[@id="id"]
		if (preg_match('/^([a-z]+)#([a-z0-9_-]+)$/i', $css, $m)) {
			return '//' . $m[1] . '[@id="' . $m[2] . '"]';
		}

		// .class → //*[contains(@class, "class")]
		if (preg_match('/^\.([a-z0-9_-]+)$/i', $css, $m)) {
			return '//*[contains(@class, "' . $m[1] . '")]';
		}

		// Reiner Tag
		if (preg_match('/^[a-z]+$/i', $css)) {
			return '//' . $css;
		}

		return '//' . $css;
	}

	/**
	 * HTML-Snippet um den markierten Text extrahieren.
	 */
	private function extract_html_around_text(string $html, string $text, int $max_length): ?string {
		$needle = mb_substr($text, 0, 100);
		$pos = mb_strpos($html, $needle);
		if ($pos === false) return null;

		$start = max(0, $pos - (int)($max_length / 2));
		return mb_substr($html, $start, $max_length);
	}

	/**
	 * Validiert eine Regel gegen echtes HTML.
	 * @return array{valid: bool, reason?: string, content_length?: int}
	 */
	private function validate_rule(string $html, string $xpath_selector, string $expected_text): array {
		$doc = new DOMDocument();
		$prev = libxml_use_internal_errors(true);
		$doc->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
		libxml_use_internal_errors($prev);
		$xpath = new DOMXPath($doc);

		$nodes = @$xpath->query($xpath_selector);
		if (!$nodes || $nodes->length === 0) {
			return ['valid' => false, 'reason' => 'Selektor trifft kein Element'];
		}

		$content = $doc->saveHTML($nodes->item(0));
		$text = strip_tags($content);
		$text_len = mb_strlen($text);

		// Enthält den selektierten Text?
		$check_text = mb_substr($expected_text, 0, 100);
		if (mb_strpos($text, $check_text) === false) {
			return ['valid' => false, 'reason' => 'Markierter Text nicht im Extraktionsergebnis gefunden'];
		}

		if ($text_len < 200) {
			return ['valid' => false, 'reason' => 'Extrahierter Inhalt zu kurz (' . $text_len . ' Zeichen)'];
		}

		$body_len = mb_strlen(strip_tags($html));
		if ($body_len > 0 && $text_len > $body_len * 0.95) {
			return ['valid' => false, 'reason' => 'Selektor erfasst fast die gesamte Seite'];
		}

		return ['valid' => true, 'content_length' => $text_len];
	}

	/**
	 * AJAX: Regeln für Prefs-Tab auflisten.
	 */
	function get_rules(): void {
		$owner_uid = $_SESSION['uid'];

		$sth = $this->pdo->prepare("SELECT id, domain, rule_type, selector_css,
				selector_xpath, confidence, hit_count, miss_count, is_active,
				llm_reasoning, sample_url, created_at
			FROM ttrss_plugin_parser_rules
			WHERE owner_uid = ?
			ORDER BY domain, created_at DESC");
		$sth->execute([$owner_uid]);

		$rules = $sth->fetchAll(PDO::FETCH_ASSOC);
		print json_encode(['rules' => $rules]);
	}

	/**
	 * AJAX: Regel aktivieren/deaktivieren.
	 */
	function toggle_rule(): void {
		$id = (int)clean($_POST['id'] ?? 0);
		$owner_uid = $_SESSION['uid'];

		$sth = $this->pdo->prepare("UPDATE ttrss_plugin_parser_rules
			SET is_active = NOT is_active, updated_at = NOW()
			WHERE id = ? AND owner_uid = ?");
		$sth->execute([$id, $owner_uid]);

		print json_encode(['success' => true]);
	}

	/**
	 * AJAX: Regel löschen.
	 */
	function delete_rule(): void {
		$id = (int)clean($_POST['id'] ?? 0);
		$owner_uid = $_SESSION['uid'];

		$sth = $this->pdo->prepare("DELETE FROM ttrss_plugin_parser_rules
			WHERE id = ? AND owner_uid = ?");
		$sth->execute([$id, $owner_uid]);

		print json_encode(['success' => true]);
	}

	/**
	 * AJAX: Regel testen (re-fetch + Extraktion anzeigen).
	 */
	function test_rule(): void {
		$id = (int)clean($_POST['id'] ?? 0);
		$owner_uid = $_SESSION['uid'];

		$sth = $this->pdo->prepare("SELECT * FROM ttrss_plugin_parser_rules
			WHERE id = ? AND owner_uid = ?");
		$sth->execute([$id, $owner_uid]);
		$rule = $sth->fetch(PDO::FETCH_ASSOC);

		if (!$rule) {
			print json_encode(['error' => 'Regel nicht gefunden']);
			return;
		}

		$html = UrlHelper::fetch([
			'url' => $rule['sample_url'],
			'timeout' => 15,
			'followlocation' => true
		]);

		if (!$html) {
			print json_encode(['error' => 'Seite konnte nicht geladen werden']);
			return;
		}

		$doc = new DOMDocument();
		$prev = libxml_use_internal_errors(true);
		$doc->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
		libxml_use_internal_errors($prev);
		$xpath = new DOMXPath($doc);

		if (!$this->is_safe_xpath($rule['selector_xpath'])) {
			print json_encode(['error' => 'Ungültiger XPath-Selektor']);
			return;
		}

		$nodes = @$xpath->query($rule['selector_xpath']);
		if (!$nodes || $nodes->length === 0) {
			print json_encode([
				'error' => 'Selektor trifft kein Element auf der aktuellen Seite',
				'selector' => $rule['selector_xpath']
			]);
			return;
		}

		$content = $doc->saveHTML($nodes->item(0));
		$text_len = mb_strlen(strip_tags($content));

		print json_encode([
			'success' => true,
			'content_preview' => mb_substr(strip_tags($content), 0, 500),
			'content_length' => $text_len,
			'elements_found' => $nodes->length,
		]);
	}

	/**
	 * AJAX: Regel per LLM regenerieren.
	 */
	function regenerate_rule(): void {
		$id = (int)clean($_POST['id'] ?? 0);
		$owner_uid = $_SESSION['uid'];

		$sth = $this->pdo->prepare("SELECT * FROM ttrss_plugin_parser_rules
			WHERE id = ? AND owner_uid = ?");
		$sth->execute([$id, $owner_uid]);
		$rule = $sth->fetch(PDO::FETCH_ASSOC);

		if (!$rule) {
			print json_encode(['error' => 'Regel nicht gefunden']);
			return;
		}

		// Frische Seite laden
		$html = UrlHelper::fetch([
			'url' => $rule['sample_url'],
			'timeout' => 15,
			'followlocation' => true
		]);

		$html_context = $rule['sample_html'];
		if ($html) {
			$snippet = $this->extract_html_around_text($html, $rule['sample_text'], 3000);
			if ($snippet) $html_context = $snippet;
		}

		$llm_result = $this->ask_llm_for_selector(
			$rule['sample_text'],
			'',
			$html_context,
			$rule['rule_type'],
			$rule['domain']
		);

		if (!$llm_result) {
			print json_encode(['error' => 'LLM-Aufruf fehlgeschlagen']);
			return;
		}

		// XPath-Selektor-Sicherheitsprüfung
		if (!$this->is_safe_xpath($llm_result['xpath_selector'])) {
			print json_encode(['error' => 'Regenerierter XPath-Selektor ist ungültig oder unsicher']);
			return;
		}

		// Validieren
		if ($html) {
			$validation = $this->validate_rule($html, $llm_result['xpath_selector'], $rule['sample_text']);
			if (!$validation['valid']) {
				print json_encode([
					'error' => 'Neuer Selektor besteht Validierung nicht: ' . $validation['reason'],
					'selector' => $llm_result,
				]);
				return;
			}
		}

		// Aktualisieren
		$sth = $this->pdo->prepare("UPDATE ttrss_plugin_parser_rules
			SET selector_css = ?, selector_xpath = ?, confidence = ?,
				llm_reasoning = ?, hit_count = 0, miss_count = 0,
				is_active = TRUE, updated_at = NOW()
			WHERE id = ? AND owner_uid = ?");
		$sth->execute([
			mb_substr($llm_result['css_selector'], 0, self::MAX_SELECTOR_LENGTH),
			mb_substr($llm_result['xpath_selector'], 0, self::MAX_SELECTOR_LENGTH),
			$llm_result['confidence'],
			mb_substr($llm_result['reasoning'], 0, 2000),
			$id,
			$owner_uid,
		]);

		print json_encode([
			'success' => true,
			'css_selector' => $llm_result['css_selector'],
			'xpath_selector' => $llm_result['xpath_selector'],
			'confidence' => $llm_result['confidence'],
			'reasoning' => $llm_result['reasoning'],
		]);
	}

	/**
	 * AJAX: Prüft, ob für eine Domain aktive Regeln existieren.
	 */
	function has_rules(): void {
		$domain = clean($_POST['domain'] ?? $_GET['domain'] ?? '');
		$domain = $this->normalize_domain_str($domain);
		$owner_uid = $_SESSION['uid'] ?? 0;
		if (!$owner_uid) {
			print json_encode(['count' => 0]);
			return;
		}

		$sth = $this->pdo->prepare("SELECT COUNT(*) AS cnt
			FROM ttrss_plugin_parser_rules
			WHERE owner_uid = ? AND domain = ? AND is_active = TRUE");
		$sth->execute([$owner_uid, $domain]);
		$row = $sth->fetch();

		print json_encode(['count' => (int)$row['cnt']]);
	}

	// ── Prefs-Tab ──────────────────────────────────

	function hook_prefs_tab(string $args): void {
		if ($args != "prefFeeds") return;

		?>
		<div dojoType="dijit.layout.AccordionPane"
			title="<i class='material-icons'>auto_fix_high</i> <?= __('Parser-Regeln (Domain-spezifisch)') ?>">

			<?= format_notice(__("Domain-spezifische Extraktionsregeln für den Volltext-Parser. Regeln werden aus Textauswahl im Reader Mode gelernt und per KI optimiert. Bei Fehlschlag greift automatisch der Standard-Parser.")) ?>

			<div id="parser-rules-list">
				<p><i class="material-icons" style="vertical-align: middle">hourglass_empty</i> <?= __('Lade Regeln...') ?></p>
			</div>

			<script type="text/javascript">
				document.addEventListener('DOMContentLoaded', function () {
					if (typeof Plugins !== 'undefined' && Plugins.Parser_Rules) {
						Plugins.Parser_Rules.loadPrefsRules();
					}
				});
			</script>
		</div>
		<?php
	}

	// ── Hilfsfunktionen ────────────────────────────

	/**
	 * Prüft ob ein XPath-Selektor sicher ausgeführt werden kann.
	 * Blockiert gefährliche Funktionen und erzwingt Längenlimit.
	 */
	private function is_safe_xpath(string $xpath): bool {
		if (empty($xpath)) return false;
		if (mb_strlen($xpath) > self::MAX_SELECTOR_LENGTH) return false;

		// Gefährliche XPath-Funktionen blockieren (document(), php:function(), etc.)
		$dangerous = [
			'/\bdocument\s*\(/i',
			'/\bphp\s*:\s*function/i',
			'/\bphp\s*:\s*functionString/i',
			'/\bsystem-property\s*\(/i',
			'/\bunparsed-entity-uri\s*\(/i',
		];
		foreach ($dangerous as $pattern) {
			if (preg_match($pattern, $xpath)) return false;
		}

		// Syntaktische Validierung: testweise gegen leeres Dokument ausführen
		$doc = new DOMDocument();
		$doc->loadHTML('<html><body></body></html>');
		$xp = new DOMXPath($doc);
		$result = @$xp->query($xpath);
		if ($result === false) return false;

		return true;
	}

	private function normalize_domain(string $url): ?string {
		$host = parse_url($url, PHP_URL_HOST);
		if (!$host) return null;
		return $this->normalize_domain_str($host);
	}

	private function normalize_domain_str(string $domain): string {
		$domain = strtolower(trim($domain));
		if (str_starts_with($domain, 'www.')) {
			$domain = substr($domain, 4);
		}
		return $domain;
	}

	function api_version(): int {
		return 2;
	}
}
