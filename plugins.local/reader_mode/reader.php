<?php
	define('TTRSS_ROOT', dirname(dirname(__DIR__)));
	chdir(TTRSS_ROOT);
	require_once TTRSS_ROOT . '/include/autoload.php';
	require_once TTRSS_ROOT . '/include/sessions.php';

	Config::sanity_check();

	if (!init_plugins()) return;

	UserHelper::login_sequence();

	if (empty($_SESSION['uid'])) {
		header('Location: ../../index.php');
		exit;
	}

	$article_id = (int)($_GET['id'] ?? 0);
	if (!$article_id) {
		header('Location: ../../index.php');
		exit;
	}

	$pdo = Db::pdo();

	// Artikel laden + Berechtigung prüfen
	$sth = $pdo->prepare("
		SELECT e.title, e.content, e.cached_content, e.link, e.author,
			e.updated, e.lang, e.date_entered,
			ue.marked, ue.unread, ue.feed_id,
			f.title AS feed_title, f.id AS feed_id, f.favicon_avg_color
		FROM ttrss_entries e
		JOIN ttrss_user_entries ue ON e.id = ue.ref_id
		JOIN ttrss_feeds f ON ue.feed_id = f.id
		WHERE e.id = ? AND ue.owner_uid = ?
	");
	$sth->execute([$article_id, $_SESSION['uid']]);
	$article = $sth->fetch(PDO::FETCH_ASSOC);

	if (!$article) {
		header('Location: ../../index.php');
		exit;
	}

	// Volltext: cached_content bevorzugen, sonst Feed-Content
	$content = !empty($article['cached_content'])
		? $article['cached_content']
		: $article['content'];

	// Volltext via Hook versuchen wenn kein cached_content
	if (empty($article['cached_content'])) {
		$fulltext = '';
		PluginHost::getInstance()->run_hooks_callback(
			PluginHost::HOOK_GET_FULL_TEXT,
			function ($result) use (&$fulltext) {
				if ($result) {
					$fulltext = $result;
					return true; // Abbrechen nach erstem Ergebnis
				}
			},
			$article['link']
		);
		if ($fulltext) {
			$content = $fulltext;
			// In Cache speichern
			$cache_sth = $pdo->prepare("UPDATE ttrss_entries SET cached_content = ? WHERE id = ?");
			$cache_sth->execute([$fulltext, $article_id]);
		}
	}

	$content = Sanitizer::sanitize($content, false, $_SESSION['uid'], $article['link']);

	// Annotations laden
	$sth = $pdo->prepare("SELECT id, selector_path, start_offset, end_offset,
		highlighted_text, note, color, markers, created_at
		FROM ttrss_plugin_annotations
		WHERE ref_id = ? AND owner_uid = ? ORDER BY id");
	$sth->execute([$article_id, $_SESSION['uid']]);

	$annotations = [];
	while ($row = $sth->fetch()) {
		$annotations[] = $row;
	}
	$annotations_json = json_encode($annotations, JSON_HEX_TAG | JSON_HEX_AMP);

	// Labels laden
	$labels = Article::_get_labels($article_id, $_SESSION['uid']);
	$labels_json = json_encode($labels, JSON_HEX_TAG);

	// Domain extrahieren
	$domain = parse_url($article['link'], PHP_URL_HOST);
	if ($domain && str_starts_with($domain, 'www.')) {
		$domain = substr($domain, 4);
	}

	// Wörter / Lesezeit
	$word_count = str_word_count(strip_tags($content));
	$reading_time = max(1, (int)round($word_count / 200));

	// Metadaten als JSON
	$meta = json_encode([
		'id' => $article_id,
		'title' => $article['title'],
		'link' => $article['link'],
		'author' => $article['author'],
		'domain' => $domain,
		'feed_title' => $article['feed_title'],
		'feed_id' => (int)$article['feed_id'],
		'published' => $article['updated'],
		'saved' => $article['date_entered'],
		'lang' => $article['lang'] ?: 'de',
		'marked' => (bool)$article['marked'],
		'word_count' => $word_count,
		'reading_time' => $reading_time,
		'labels' => $labels,
		'favicon_avg_color' => $article['favicon_avg_color'],
	], JSON_HEX_TAG | JSON_HEX_AMP);

	// Reader-Mode-Settings laden
	$plugin = PluginHost::getInstance()->get_plugin('reader_mode');
	$settings = [
		'font_size' => 17,
		'line_height' => 1.7,
		'paragraph_spacing' => 1.2,
		'content_width' => 'medium',
		'font_family' => 'sans',
	];
	if ($plugin) {
		$host = PluginHost::getInstance();
		$settings['font_size'] = (int)$host->get($plugin, 'font_size', 17);
		$settings['line_height'] = (float)$host->get($plugin, 'line_height', 1.7);
		$settings['paragraph_spacing'] = (float)$host->get($plugin, 'paragraph_spacing', 1.2);
		$settings['content_width'] = $host->get($plugin, 'content_width', 'medium');
		$settings['font_family'] = $host->get($plugin, 'font_family', 'sans');
	}
	$settings_json = json_encode($settings, JSON_HEX_TAG);

	// CSRF Token
	$csrf_token = $_SESSION['csrf_token'];

	header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($article['lang'] ?: 'de') ?>">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?= htmlspecialchars($article['title']) ?> — Reader</title>
	<link rel="shortcut icon" type="image/png" href="../../images/favicon.png">
	<link rel="stylesheet" href="https://fonts.googleapis.com/icon?family=Material+Icons">
	<link rel="stylesheet" href="reader_page.css">
</head>
<body class="reader-body <?= $settings['font_family'] === 'serif' ? 'serif-font' : '' ?>"
	data-article-id="<?= $article_id ?>"
	style="--reader-font-size: <?= $settings['font_size'] ?>px;
		--reader-line-height: <?= $settings['line_height'] ?>;
		--reader-paragraph-spacing: <?= $settings['paragraph_spacing'] ?>em;">

	<script>
		window.__readerMeta = <?= $meta ?>;
		window.__readerAnnotations = <?= $annotations_json ?>;
		window.__readerSettings = <?= $settings_json ?>;
		window.__csrfToken = "<?= $csrf_token ?>";
	</script>

	<!-- Toolbar -->
	<header class="reader-toolbar" id="reader-toolbar">
		<div class="toolbar-left">
			<button class="toolbar-btn" onclick="history.back()" title="Zurück">
				<i class="material-icons">arrow_back</i>
			</button>
			<button class="toolbar-btn" id="btn-prev" title="Vorheriger Artikel" disabled>
				<i class="material-icons">keyboard_arrow_up</i>
			</button>
			<button class="toolbar-btn" id="btn-next" title="Nächster Artikel" disabled>
				<i class="material-icons">keyboard_arrow_down</i>
			</button>
		</div>
		<div class="toolbar-center">
			<button class="toolbar-btn" id="btn-typography" title="Darstellung">
				<i class="material-icons">text_format</i>
			</button>
			<div class="toolbar-width-group" id="width-group">
				<button class="toolbar-btn-width" data-width="narrow" title="Schmal">S</button>
				<button class="toolbar-btn-width" data-width="medium" title="Mittel">M</button>
				<button class="toolbar-btn-width" data-width="wide" title="Breit">L</button>
			</div>
		</div>
		<div class="toolbar-right">
			<button class="toolbar-btn" id="btn-toc-toggle" title="Inhaltsverzeichnis (t)">
				<i class="material-icons">toc</i>
			</button>
			<button class="toolbar-btn" id="btn-sidebar-toggle" title="Seitenleiste (i)">
				<i class="material-icons">info_outline</i>
			</button>
			<button class="toolbar-btn" id="btn-star" title="Stern (s)">
				<i class="material-icons"><?= $article['marked'] ? 'star' : 'star_border' ?></i>
			</button>
			<a class="toolbar-btn" href="<?= htmlspecialchars($article['link']) ?>" target="_blank" title="Original öffnen">
				<i class="material-icons">open_in_new</i>
			</a>
		</div>
	</header>

	<!-- Typography Dropdown -->
	<div class="typography-dropdown" id="typography-dropdown" style="display: none">
		<label class="typo-label">
			<span>Schriftgröße</span>
			<input type="range" id="typo-font-size" min="14" max="22" step="1" value="<?= $settings['font_size'] ?>">
			<span class="typo-value" id="typo-font-size-val"><?= $settings['font_size'] ?>px</span>
		</label>
		<label class="typo-label">
			<span>Zeilenhöhe</span>
			<input type="range" id="typo-line-height" min="1.4" max="2.2" step="0.1" value="<?= $settings['line_height'] ?>">
			<span class="typo-value" id="typo-line-height-val"><?= $settings['line_height'] ?></span>
		</label>
		<label class="typo-label">
			<span>Absatzabstand</span>
			<input type="range" id="typo-paragraph-spacing" min="0.8" max="2.0" step="0.1" value="<?= $settings['paragraph_spacing'] ?>">
			<span class="typo-value" id="typo-paragraph-spacing-val"><?= $settings['paragraph_spacing'] ?>em</span>
		</label>
		<div class="typo-font-toggle">
			<button class="typo-font-btn <?= $settings['font_family'] === 'sans' ? 'active' : '' ?>" data-font="sans">Sans</button>
			<button class="typo-font-btn <?= $settings['font_family'] === 'serif' ? 'active' : '' ?>" data-font="serif">Serif</button>
		</div>
	</div>

	<!-- Hauptlayout -->
	<div class="reader-layout">

		<!-- TOC (links) -->
		<aside class="reader-toc" id="reader-toc">
			<div class="toc-title"><?= htmlspecialchars($article['title']) ?></div>
			<nav class="toc-list" id="toc-list"></nav>
		</aside>

		<!-- Content (Mitte) -->
		<main class="reader-content" id="reader-content" data-width="<?= htmlspecialchars($settings['content_width']) ?>">
			<article class="annotations-wrapper"
				data-article-id="<?= $article_id ?>"
				data-annotations='<?= htmlspecialchars($annotations_json, ENT_QUOTES) ?>'>
				<?= $content ?>
			</article>
		</main>

		<!-- Sidebar (rechts) -->
		<aside class="reader-sidebar" id="reader-sidebar">
			<div class="sidebar-tabs">
				<button class="sidebar-tab active" data-panel="sidebar-info">Info</button>
				<button class="sidebar-tab" data-panel="sidebar-notes">Notizen</button>
			</div>
			<div class="sidebar-panel active" id="sidebar-info"></div>
			<div class="sidebar-panel" id="sidebar-notes"></div>
		</aside>

	</div>

	<!-- Fortschrittsbalken -->
	<div class="reader-progress" id="reader-progress">
		<div class="reader-progress-bar" id="reader-progress-bar"></div>
	</div>

	<script src="reader_page.js"></script>
</body>
</html>
