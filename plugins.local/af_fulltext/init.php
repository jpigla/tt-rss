<?php
class Af_Fulltext extends Plugin {

	/** @var PluginHost $host */
	private $host;

	function about() {
		return [1.0,
			"Volltext-Extraktion: Lädt den kompletten Artikelinhalt direkt im Reader",
			"tt-ss",
			false];
	}

	function init($host) {
		$this->host = $host;

		$host->add_hook($host::HOOK_ARTICLE_BUTTON, $this);
		$host->add_hook($host::HOOK_GET_FULL_TEXT, $this);
		$host->add_hook($host::HOOK_PREFS_TAB, $this);
	}

	function get_js() {
		return file_get_contents(__DIR__ . "/af_fulltext.js");
	}

	function get_css() {
		return file_get_contents(__DIR__ . "/af_fulltext.css");
	}

	function hook_article_button($line) {
		return "<i class='material-icons af-fulltext-btn'
			data-article-id='" . $line["id"] . "'
			onclick=\"Plugins.Af_Fulltext.fetch(" . $line["id"] . ")\"
			style='cursor: pointer'
			title=\"" . __('Volltext laden') . "\">article</i>";
	}

	/**
	 * Implementiert HOOK_GET_FULL_TEXT -- wird von anderen Plugins aufgerufen.
	 * @param string $url
	 * @return string|false
	 */
	function hook_get_full_text($url) {
		return $this->extract_article_content($url);
	}

	/**
	 * Extrahiert den Hauptinhalt einer Webseite.
	 * Einfache Heuristik basierend auf content-Dichte und semantischen HTML5-Tags.
	 * @param string $url
	 * @return string|false
	 */
	private function extract_article_content(string $url) {
		$content = UrlHelper::fetch([
			'url' => $url,
			'timeout' => 15,
			'followlocation' => true
		]);

		if (!$content) return false;

		$doc = new DOMDocument();
		$prev = libxml_use_internal_errors(true);
		$doc->loadHTML('<?xml encoding="utf-8" ?>' . $content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
		libxml_use_internal_errors($prev);

		// Störende Elemente entfernen
		$remove_tags = ['script', 'style', 'nav', 'header', 'footer', 'aside',
			'form', 'iframe', 'noscript', 'svg'];

		foreach ($remove_tags as $tag) {
			$elements = $doc->getElementsByTagName($tag);
			$to_remove = [];
			for ($i = 0; $i < $elements->length; $i++) {
				$to_remove[] = $elements->item($i);
			}
			foreach ($to_remove as $el) {
				if ($el->parentNode) {
					$el->parentNode->removeChild($el);
				}
			}
		}

		// Elemente mit typischen Nicht-Content-Klassen entfernen
		$xpath = new DOMXPath($doc);
		$noise_patterns = [
			'sidebar', 'widget', 'comment', 'advertisement', 'ad-', 'social-share',
			'related-posts', 'newsletter', 'popup', 'modal', 'cookie', 'banner',
			'menu', 'breadcrumb', 'pagination'
		];

		foreach ($noise_patterns as $pattern) {
			$nodes = $xpath->query("//*[contains(@class, '$pattern') or contains(@id, '$pattern')]");
			if ($nodes) {
				$to_remove = [];
				foreach ($nodes as $node) {
					$to_remove[] = $node;
				}
				foreach ($to_remove as $node) {
					if ($node->parentNode) {
						$node->parentNode->removeChild($node);
					}
				}
			}
		}

		// Versuch 1: Semantische HTML5-Tags nutzen
		$article_content = $this->extract_from_semantic_tags($doc, $xpath);
		if ($article_content && mb_strlen(strip_tags($article_content)) > 200) {
			return $article_content;
		}

		// Versuch 2: Größter Text-Block finden
		$best_content = $this->extract_largest_text_block($doc, $xpath);
		if ($best_content && mb_strlen(strip_tags($best_content)) > 200) {
			return $best_content;
		}

		// Versuch 3: body-Inhalt (Fallback)
		$body = $doc->getElementsByTagName('body')->item(0);
		if ($body) {
			$html = '';
			foreach ($body->childNodes as $child) {
				$html .= $doc->saveHTML($child);
			}
			return $html;
		}

		return false;
	}

	/**
	 * Versucht Inhalt aus <article>, <main> oder role="main" zu extrahieren.
	 * @param DOMDocument $doc
	 * @param DOMXPath $xpath
	 * @return string|false
	 */
	private function extract_from_semantic_tags(DOMDocument $doc, DOMXPath $xpath) {
		// Prioritätsreihenfolge
		$queries = [
			'//article',
			'//main',
			'//*[@role="main"]',
			'//*[contains(@class, "article-content") or contains(@class, "article-body")]',
			'//*[contains(@class, "post-content") or contains(@class, "post-body")]',
			'//*[contains(@class, "entry-content") or contains(@class, "entry-body")]',
			'//*[contains(@class, "content-body") or contains(@class, "story-body")]',
			'//*[contains(@itemprop, "articleBody")]',
		];

		foreach ($queries as $query) {
			$nodes = $xpath->query($query);
			if ($nodes && $nodes->length > 0) {
				// Den längsten Treffer nehmen
				$best = null;
				$best_length = 0;
				foreach ($nodes as $node) {
					$text = trim($node->textContent);
					if (mb_strlen($text) > $best_length) {
						$best = $node;
						$best_length = mb_strlen($text);
					}
				}
				if ($best) {
					return $doc->saveHTML($best);
				}
			}
		}

		return false;
	}

	/**
	 * Findet den div/section-Block mit dem meisten Text.
	 * @param DOMDocument $doc
	 * @param DOMXPath $xpath
	 * @return string|false
	 */
	private function extract_largest_text_block(DOMDocument $doc, DOMXPath $xpath) {
		$candidates = $xpath->query('//div | //section');
		if (!$candidates) return false;

		$best = null;
		$best_score = 0;

		foreach ($candidates as $node) {
			$text = trim($node->textContent);
			$text_length = mb_strlen($text);

			// Zähle <p>-Tags als Qualitätsmerkmal
			$p_count = 0;
			$paragraphs = $xpath->query('.//p', $node);
			if ($paragraphs) {
				$p_count = $paragraphs->length;
			}

			// Score: Textlänge * Anzahl Absätze
			$score = $text_length * max(1, $p_count);

			// Abwertung für sehr verschachtelte Container
			$child_divs = $xpath->query('./div | ./section', $node);
			if ($child_divs && $child_divs->length > 5) {
				$score *= 0.5;
			}

			if ($score > $best_score) {
				$best = $node;
				$best_score = $score;
			}
		}

		if ($best) {
			return $doc->saveHTML($best);
		}

		return false;
	}

	/**
	 * Wird per AJAX aufgerufen -- lädt Volltext und gibt ihn zurück.
	 */
	function fetch(): void {
		$id = (int) clean($_REQUEST['id'] ?? 0);
		if ($id <= 0) return;

		$owner_uid = $_SESSION['uid'];

		// Artikel-URL holen
		$sth = $this->pdo->prepare("SELECT link, cached_content FROM ttrss_entries
			WHERE id = (SELECT ref_id FROM ttrss_user_entries WHERE ref_id = ? AND owner_uid = ?)");
		$sth->execute([$id, $owner_uid]);

		$row = $sth->fetch();
		if (!$row) {
			print json_encode(["error" => "Artikel nicht gefunden"]);
			return;
		}

		// Gecachten Volltext prüfen
		if (!empty($row['cached_content'])) {
			print json_encode([
				"id" => $id,
				"content" => $row['cached_content'],
				"cached" => true
			]);
			return;
		}

		$url = $row['link'];
		if (empty($url)) {
			print json_encode(["error" => "Keine URL vorhanden"]);
			return;
		}

		$content = $this->extract_article_content($url);

		if ($content === false) {
			print json_encode(["error" => "Volltext konnte nicht extrahiert werden"]);
			return;
		}

		// Sanitize
		$content = Sanitizer::sanitize($content, false, $owner_uid);

		// In Cache speichern
		$sth = $this->pdo->prepare("UPDATE ttrss_entries SET cached_content = ?
			WHERE id = (SELECT ref_id FROM ttrss_user_entries WHERE ref_id = ? AND owner_uid = ?)");
		$sth->execute([$content, $id, $owner_uid]);

		print json_encode([
			"id" => $id,
			"content" => $content,
			"cached" => false
		]);
	}

	function hook_prefs_tab($args) {
		if ($args != "prefFeeds") return;

		?>
		<div dojoType="dijit.layout.AccordionPane"
			title="<i class='material-icons'>article</i> <?= __('Volltext-Extraktion (af_fulltext)') ?>">

			<?= format_notice(__("Dieses Plugin extrahiert den vollständigen Artikeltext direkt im Reader. Klicke auf das Artikel-Symbol neben einem Artikel, um den Volltext zu laden. Einmal geladener Volltext wird gecacht.")) ?>

			<p><?= __("Die Extraktion nutzt semantische HTML5-Tags (article, main) und Textdichte-Heuristiken. Bei komplexen Seiten kann das Ergebnis variieren.") ?></p>
		</div>
		<?php
	}

	function api_version() {
		return 2;
	}
}
