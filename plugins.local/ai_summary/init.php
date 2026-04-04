<?php
class Ai_Summary extends Plugin {

	/** @var PluginHost $host */
	private $host;

	const TYPE_SHORT = 'short';       // 1-2 Sätze
	const TYPE_BULLETS = 'bullets';    // Stichpunkte
	const TYPE_DETAILED = 'detailed';  // Absatz

	function about() {
		return [1.0,
			"KI-gestützte Artikel-Zusammenfassungen (benötigt ai_core)",
			"tt-ss",
			false];
	}

	function init($host) {
		$this->host = $host;

		$host->add_hook($host::HOOK_ARTICLE_BUTTON, $this);
		$host->add_hook($host::HOOK_RENDER_ARTICLE_CDM, $this);
		$host->add_hook($host::HOOK_RENDER_ARTICLE, $this);

		$m = new Db_Migrations();
		$m->initialize_for_plugin($this);
		$m->migrate();
	}

	function get_js() {
		return file_get_contents(__DIR__ . "/ai_summary.js");
	}

	function get_css() {
		return file_get_contents(__DIR__ . "/ai_summary.css");
	}

	function hook_article_button($line) {
		$id = $line['id'];

		return "<i class='material-icons ai-summary-btn'
			data-article-id='$id'
			onclick=\"Plugins.Ai_Summary.generate($id)\"
			style='cursor: pointer'
			title=\"" . __('KI-Zusammenfassung') . "\">auto_awesome</i>";
	}

	/**
	 * Zeigt gecachte Zusammenfassungen im Artikel an.
	 * @param array<string, mixed> $article
	 * @return array<string, mixed>
	 */
	private function inject_cached_summary(array $article): array {
		$id = $article['id'] ?? 0;
		if ($id <= 0) return $article;

		$owner_uid = $_SESSION['uid'] ?? 0;
		if (!$owner_uid) return $article;

		$sth = $this->pdo->prepare("SELECT summary_text, summary_type, model_used, created_at
			FROM ttrss_plugin_ai_summaries
			WHERE ref_id = ? AND owner_uid = ?
			ORDER BY created_at DESC LIMIT 1");
		$sth->execute([$id, $owner_uid]);

		$row = $sth->fetch();
		if (!$row) return $article;

		$summary_html = $this->format_summary($row['summary_text'], $row['summary_type']);
		$model = htmlspecialchars($row['model_used']);
		$date = TimeHelper::smart_date_time(strtotime($row['created_at']));

		$article['content'] = "<div class='ai-summary-box' id='ai-summary-$id'>
			<div class='ai-summary-header'>
				<i class='material-icons'>auto_awesome</i>
				<span>" . __('KI-Zusammenfassung') . "</span>
				<span class='ai-summary-meta'>$model &middot; $date</span>
			</div>
			<div class='ai-summary-content'>$summary_html</div>
		</div>" . $article['content'];

		return $article;
	}

	function hook_render_article_cdm($article) {
		return $this->inject_cached_summary($article);
	}

	function hook_render_article($article) {
		return $this->inject_cached_summary($article);
	}

	/**
	 * Formatiert den Zusammenfassungstext als HTML.
	 * @param string $text
	 * @param string $type
	 * @return string
	 */
	private function format_summary(string $text, string $type): string {
		$text = htmlspecialchars($text);

		if ($type === self::TYPE_BULLETS) {
			// Stichpunkte in Liste umwandeln
			$lines = array_filter(explode("\n", $text), fn($l) => trim($l) !== '');
			$items = array_map(function($line) {
				$line = preg_replace('/^[\-\*\•]\s*/', '', $line);
				return "<li>$line</li>";
			}, $lines);
			return "<ul>" . implode('', $items) . "</ul>";
		}

		return nl2br($text);
	}

	/**
	 * Erstellt die System-Prompts je nach Typ.
	 * @param string $type
	 * @param string $lang Sprache des Artikels
	 * @return string
	 */
	private function get_system_prompt(string $type, string $lang = 'de'): string {
		$lang_hint = $lang === 'de' ? 'Antworte auf Deutsch.' : 'Antworte in der Sprache des Artikels.';

		switch ($type) {
			case self::TYPE_SHORT:
				return "Du bist ein präziser Zusammenfassungs-Assistent. Fasse den folgenden Artikel in 1-2 kurzen Sätzen zusammen. $lang_hint";
			case self::TYPE_BULLETS:
				return "Du bist ein präziser Zusammenfassungs-Assistent. Fasse den folgenden Artikel in 3-5 Stichpunkten zusammen. Jeder Punkt beginnt mit einem Bindestrich. $lang_hint";
			case self::TYPE_DETAILED:
				return "Du bist ein präziser Zusammenfassungs-Assistent. Fasse den folgenden Artikel in einem kurzen Absatz (3-5 Sätze) zusammen. Erfasse die Kernaussagen und den Kontext. $lang_hint";
			default:
				return "Fasse den folgenden Artikel kurz zusammen. $lang_hint";
		}
	}

	/**
	 * Generiert eine Zusammenfassung per AJAX.
	 */
	function generate(): void {
		$id = (int) clean($_REQUEST['id'] ?? 0);
		$type = clean($_REQUEST['type'] ?? self::TYPE_SHORT);
		$owner_uid = $_SESSION['uid'];

		if ($id <= 0) {
			print json_encode(["error" => "Ungültige Artikel-ID"]);
			return;
		}

		// Prüfe ob ai_core verfügbar ist
		if (!PluginHost::getInstance()->get_plugin('ai_core')) {
			print json_encode(["error" => "Plugin ai_core ist nicht aktiviert"]);
			return;
		}

		// Gecachte Zusammenfassung prüfen
		$sth = $this->pdo->prepare("SELECT summary_text, model_used FROM ttrss_plugin_ai_summaries
			WHERE ref_id = ? AND owner_uid = ? AND summary_type = ?
			ORDER BY created_at DESC LIMIT 1");
		$sth->execute([$id, $owner_uid, $type]);

		if ($row = $sth->fetch()) {
			print json_encode([
				"id" => $id,
				"summary" => $row['summary_text'],
				"type" => $type,
				"model" => $row['model_used'],
				"cached" => true
			]);
			return;
		}

		// Artikelinhalt holen
		$sth = $this->pdo->prepare("SELECT e.title, e.content, e.lang
			FROM ttrss_entries e
			JOIN ttrss_user_entries ue ON ue.ref_id = e.id
			WHERE e.id = ? AND ue.owner_uid = ?");
		$sth->execute([$id, $owner_uid]);

		$article = $sth->fetch();
		if (!$article) {
			print json_encode(["error" => "Artikel nicht gefunden"]);
			return;
		}

		$content = strip_tags($article['content']);
		$title = $article['title'];
		$lang = $article['lang'] ?? 'de';

		// Text kürzen wenn zu lang (ca. 8000 Zeichen, damit Token-Limit nicht überschritten wird)
		if (mb_strlen($content) > 8000) {
			$content = mb_substr($content, 0, 8000) . '...';
		}

		$system_prompt = $this->get_system_prompt($type, $lang);
		$user_message = "Titel: $title\n\nInhalt:\n$content";

		$config = Ai_Core::get_config();
		$summary = Ai_Core::complete($system_prompt, $user_message, 500);

		if ($summary === false) {
			print json_encode(["error" => "KI-Zusammenfassung fehlgeschlagen. Bitte Konfiguration prüfen."]);
			return;
		}

		// Zusammenfassung cachen
		$sth = $this->pdo->prepare("INSERT INTO ttrss_plugin_ai_summaries
			(ref_id, owner_uid, summary_type, summary_text, model_used)
			VALUES (?, ?, ?, ?, ?)");
		$sth->execute([$id, $owner_uid, $type, $summary, $config['model']]);

		print json_encode([
			"id" => $id,
			"summary" => $summary,
			"type" => $type,
			"model" => $config['model'],
			"cached" => false
		]);
	}

	function api_version() {
		return 2;
	}
}
