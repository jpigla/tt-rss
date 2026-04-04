<?php
class Ai_Chat extends Plugin {

	/** @var PluginHost $host */
	private $host;

	function about() {
		return [1.0,
			"Fragen zu Artikeln stellen per KI-Chat (benötigt ai_core)",
			"tt-ss",
			false];
	}

	function init($host) {
		$this->host = $host;

		$host->add_hook($host::HOOK_ARTICLE_BUTTON, $this);
	}

	function get_js() {
		return file_get_contents(__DIR__ . "/ai_chat.js");
	}

	function get_css() {
		return file_get_contents(__DIR__ . "/ai_chat.css");
	}

	function hook_article_button($line) {
		$id = (int) $line['id'];

		return "<i class='material-icons ai-chat-btn'
			data-article-id='" . $id . "'
			onclick=\"Plugins.Ai_Chat.open(" . $id . ")\"
			style='cursor: pointer'
			title=\"" . __('Fragen stellen') . "\">forum</i>";
	}

	/**
	 * Beantwortet eine Frage zum Artikel.
	 */
	function ask(): void {
		$article_id = (int) clean($_REQUEST['article_id'] ?? 0);
		$question = clean($_REQUEST['question'] ?? '');
		$owner_uid = $_SESSION['uid'];

		if ($article_id <= 0 || empty($question)) {
			print json_encode(["error" => "Ungültige Parameter"]);
			return;
		}

		if (!PluginHost::getInstance()->get_plugin('ai_core')) {
			print json_encode(["error" => "Plugin ai_core ist nicht aktiviert"]);
			return;
		}

		// Artikelinhalt laden
		$sth = $this->pdo->prepare("SELECT e.title, e.content
			FROM ttrss_entries e
			JOIN ttrss_user_entries ue ON ue.ref_id = e.id
			WHERE e.id = ? AND ue.owner_uid = ?");
		$sth->execute([$article_id, $owner_uid]);

		$article = $sth->fetch();
		if (!$article) {
			print json_encode(["error" => "Artikel nicht gefunden"]);
			return;
		}

		$content = strip_tags($article['content']);
		// Auf 6000 Zeichen kürzen
		if (mb_strlen($content) > 6000) {
			$content = mb_substr($content, 0, 6000) . '...';
		}

		$system_prompt = "Du bist ein Assistent. Beantworte Fragen basierend auf dem folgenden Artikel.\n\n" .
			"Titel: " . $article['title'] . "\n\nInhalt:\n" . $content;

		$answer = Ai_Core::complete($system_prompt, $question, 1024);

		if ($answer === false) {
			print json_encode(["error" => "KI-Anfrage fehlgeschlagen. Bitte Konfiguration prüfen."]);
			return;
		}

		print json_encode(["answer" => $answer]);
	}

	function api_version() {
		return 2;
	}
}
