<?php
class Reader_Mode extends Plugin {

	/** @var PluginHost $host */
	private $host;

	function about() {
		return [1.0,
			"Dedizierter Lesemodus mit Drei-Spalten-Layout, TOC und Metadaten",
			"tt-ss",
			false];
	}

	function init($host) {
		$this->host = $host;

		$host->add_hook($host::HOOK_ARTICLE_BUTTON, $this);
	}

	function get_js() {
		return file_get_contents(__DIR__ . '/reader_mode.js');
	}

	function get_css() {
		return file_get_contents(__DIR__ . '/reader_mode.css');
	}

	function csrf_ignore($method): bool {
		return in_array($method, ['get_article_data', 'get_adjacent_articles']);
	}

	function hook_article_button($line) {
		$id = (int) $line['id'];

		return "<i class='material-icons'
			onclick=\"window.open('plugins.local/reader_mode/reader.php?id=" . $id . "', '_blank')\"
			style='cursor:pointer'
			title=\"" . __('Im Reader öffnen') . "\">chrome_reader_mode</i>";
	}

	/**
	 * Liefert Artikeldaten + Metadaten für die Reader-Seite (AJAX).
	 */
	function get_article_data(): void {
		$id = (int)clean($_REQUEST['id'] ?? 0);

		if (!$id) {
			print json_encode(['error' => 'Fehlende ID']);
			return;
		}

		$sth = $this->pdo->prepare("
			SELECT e.id, e.title, e.content, e.cached_content, e.link, e.author,
				e.updated, e.lang, e.date_entered,
				ue.marked, ue.unread, ue.feed_id,
				f.title AS feed_title, f.id AS feed_id, f.favicon_avg_color
			FROM ttrss_entries e
			JOIN ttrss_user_entries ue ON e.id = ue.ref_id
			JOIN ttrss_feeds f ON ue.feed_id = f.id
			WHERE e.id = ? AND ue.owner_uid = ?
		");
		$sth->execute([$id, $_SESSION['uid']]);
		$article = $sth->fetch(PDO::FETCH_ASSOC);

		if (!$article) {
			print json_encode(['error' => 'Artikel nicht gefunden']);
			return;
		}

		// Volltext: cached_content bevorzugen
		$content = !empty($article['cached_content'])
			? $article['cached_content']
			: $article['content'];

		// Sanitize
		$content = Sanitizer::sanitize($content, false, $_SESSION['uid'], $article['link']);

		// Labels laden
		$labels = Article::_get_labels($id, $_SESSION['uid']);

		// Annotations laden
		$annotations = [];
		$sth = $this->pdo->prepare("SELECT id, selector_path, start_offset, end_offset,
			highlighted_text, note, color, markers, created_at
			FROM ttrss_plugin_annotations
			WHERE ref_id = ? AND owner_uid = ? ORDER BY id");
		$sth->execute([$id, $_SESSION['uid']]);
		while ($row = $sth->fetch()) {
			$annotations[] = $row;
		}

		// Domain extrahieren
		$domain = parse_url($article['link'], PHP_URL_HOST);
		if ($domain && str_starts_with($domain, 'www.')) {
			$domain = substr($domain, 4);
		}

		// Wörter zählen
		$word_count = str_word_count(strip_tags($content));

		// Lesezeit berechnen (200 WPM)
		$reading_time = max(1, (int)round($word_count / 200));

		print json_encode([
			'id' => (int)$article['id'],
			'title' => $article['title'],
			'content' => $content,
			'link' => $article['link'],
			'author' => $article['author'],
			'domain' => $domain,
			'feed_title' => $article['feed_title'],
			'feed_id' => (int)$article['feed_id'],
			'published' => $article['updated'],
			'saved' => $article['date_entered'],
			'lang' => $article['lang'] ?: 'de',
			'marked' => (bool)$article['marked'],
			'unread' => (bool)$article['unread'],
			'word_count' => $word_count,
			'reading_time' => $reading_time,
			'labels' => $labels,
			'annotations' => $annotations,
			'favicon_avg_color' => $article['favicon_avg_color'],
		]);
	}

	/**
	 * Liefert Prev/Next-Artikel-IDs im selben Feed.
	 */
	function get_adjacent_articles(): void {
		$id = (int)clean($_REQUEST['id'] ?? 0);
		$feed_id = (int)clean($_REQUEST['feed_id'] ?? 0);

		if (!$id || !$feed_id) {
			print json_encode(['prev_id' => null, 'next_id' => null]);
			return;
		}

		$prev_id = null;
		$next_id = null;

		// Nächster (neuere ref_id im selben Feed)
		$sth = $this->pdo->prepare("SELECT ue.ref_id
			FROM ttrss_user_entries ue
			WHERE ue.feed_id = ? AND ue.owner_uid = ? AND ue.ref_id > ?
			ORDER BY ue.ref_id ASC LIMIT 1");
		$sth->execute([$feed_id, $_SESSION['uid'], $id]);
		$row = $sth->fetch();
		if ($row) $next_id = (int)$row['ref_id'];

		// Vorheriger (ältere ref_id im selben Feed)
		$sth = $this->pdo->prepare("SELECT ue.ref_id
			FROM ttrss_user_entries ue
			WHERE ue.feed_id = ? AND ue.owner_uid = ? AND ue.ref_id < ?
			ORDER BY ue.ref_id DESC LIMIT 1");
		$sth->execute([$feed_id, $_SESSION['uid'], $id]);
		$row = $sth->fetch();
		if ($row) $prev_id = (int)$row['ref_id'];

		print json_encode(['prev_id' => $prev_id, 'next_id' => $next_id]);
	}

	/**
	 * Darstellungseinstellungen speichern.
	 */
	function save_settings(): void {
		$font_size = max(14, min(22, (int)clean($_REQUEST['font_size'] ?? 17)));
		$line_height = max(1.4, min(2.2, (float)clean($_REQUEST['line_height'] ?? 1.7)));
		$paragraph_spacing = max(0.8, min(2.0, (float)clean($_REQUEST['paragraph_spacing'] ?? 1.2)));
		$content_width = clean($_REQUEST['content_width'] ?? 'medium');
		$font_family = clean($_REQUEST['font_family'] ?? 'sans');

		if (!in_array($content_width, ['narrow', 'medium', 'wide'])) $content_width = 'medium';
		if (!in_array($font_family, ['sans', 'serif'])) $font_family = 'sans';

		$this->host->set($this, 'font_size', $font_size);
		$this->host->set($this, 'line_height', $line_height);
		$this->host->set($this, 'paragraph_spacing', $paragraph_spacing);
		$this->host->set($this, 'content_width', $content_width);
		$this->host->set($this, 'font_family', $font_family);

		print json_encode(['status' => 'ok']);
	}

	/**
	 * Darstellungseinstellungen laden.
	 */
	function get_settings(): void {
		print json_encode([
			'font_size' => (int)$this->host->get($this, 'font_size', 17),
			'line_height' => (float)$this->host->get($this, 'line_height', 1.7),
			'paragraph_spacing' => (float)$this->host->get($this, 'paragraph_spacing', 1.2),
			'content_width' => $this->host->get($this, 'content_width', 'medium'),
			'font_family' => $this->host->get($this, 'font_family', 'sans'),
		]);
	}

	function api_version() {
		return 2;
	}
}
