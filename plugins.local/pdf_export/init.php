<?php
class Pdf_Export extends Plugin {

	/** @var PluginHost $host */
	private $host;

	function about() {
		return [1.0,
			"Artikel als druckoptimierte HTML-Seite exportieren (Browser-PDF-Druck)",
			"tt-ss",
			false];
	}

	function init($host) {
		$this->host = $host;

		$host->add_hook($host::HOOK_ARTICLE_BUTTON, $this);
	}

	function is_public_method($method) {
		return $method === "export";
	}

	function csrf_ignore($method) {
		return $method === "export";
	}

	function get_js() {
		return <<<'JS'
Plugins.Pdf_Export = {
	export: function(id) {
		Notify.progress("Export wird vorbereitet...");

		xhr.json("backend.php", {
			op: "pluginhandler",
			plugin: "pdf_export",
			method: "get_export_url",
			id: id
		}, (reply) => {
			Notify.close();
			if (reply.url) {
				window.open(reply.url, '_blank');
			} else if (reply.error) {
				Notify.error(reply.error);
			}
		});
	}
};
JS;
	}

	/**
	 * @param array<string, mixed> $line
	 * @return string
	 */
	function hook_article_button($line) {
		$id = (int) $line['id'];

		return "<i class='material-icons'
			onclick=\"Plugins.Pdf_Export.export(" . $id . ")\"
			style='cursor: pointer'
			title=\"" . __('Als PDF exportieren') . "\">print</i>";
	}

	/**
	 * Export-URL generieren und zurückgeben.
	 */
	function get_export_url(): void {
		$id = (int)clean($_REQUEST['id'] ?? 0);

		if ($id <= 0) {
			print json_encode(["error" => __("Ungültige Artikel-ID.")]);
			return;
		}

		$owner_uid = $_SESSION['uid'];

		// Access-Key ermitteln oder erstellen
		$sth = $this->pdo->prepare("SELECT access_key FROM ttrss_access_keys
			WHERE feed_id = ? AND owner_uid = ?");
		$sth->execute([$id, $owner_uid]);
		$row = $sth->fetch();

		if ($row) {
			$access_key = $row['access_key'];
		} else {
			$access_key = bin2hex(random_bytes(16));
			$sth = $this->pdo->prepare("INSERT INTO ttrss_access_keys
				(feed_id, owner_uid, access_key) VALUES (?, ?, ?)");
			$sth->execute([$id, $owner_uid, $access_key]);
		}

		$url = Config::get_self_url() . "/backend.php?op=pluginhandler&plugin=pdf_export&pmethod=export&id=$id&key=$access_key";

		print json_encode(["url" => $url]);
	}

	/**
	 * Artikel als druckoptimierte HTML-Seite ausgeben.
	 */
	function export(): void {
		$id = (int)clean($_REQUEST['id'] ?? 0);
		$key = clean($_REQUEST['key'] ?? '');

		if ($id <= 0 || empty($key)) {
			header($_SERVER["SERVER_PROTOCOL"] . " 404 Not Found");
			print "Artikel nicht gefunden.";
			return;
		}

		// Access-Key prüfen
		$sth = $this->pdo->prepare("SELECT owner_uid FROM ttrss_access_keys
			WHERE feed_id = ? AND access_key = ?");
		$sth->execute([$id, $key]);
		$access = $sth->fetch();

		if (!$access) {
			header($_SERVER["SERVER_PROTOCOL"] . " 403 Forbidden");
			print "Zugriff verweigert.";
			return;
		}

		$owner_uid = $access['owner_uid'];

		// Artikel laden
		$sth = $this->pdo->prepare("SELECT e.title, e.content, e.link, e.author,
				SUBSTRING_FOR_DATE(e.updated, 1, 16) AS updated,
				(SELECT title FROM ttrss_feeds WHERE id = e.feed_id) AS feed_title
			FROM ttrss_entries e
			JOIN ttrss_user_entries ue ON ue.ref_id = e.id
			WHERE e.id = ? AND ue.owner_uid = ?");
		$sth->execute([$id, $owner_uid]);
		$article = $sth->fetch();

		if (!$article) {
			header($_SERVER["SERVER_PROTOCOL"] . " 404 Not Found");
			print "Artikel nicht gefunden.";
			return;
		}

		$title = htmlspecialchars($article['title'] ?? '');
		$author = htmlspecialchars($article['author'] ?? '');
		$feed_title = htmlspecialchars($article['feed_title'] ?? '');
		$updated = htmlspecialchars($article['updated'] ?? '');
		$link = htmlspecialchars($article['link'] ?? '');
		$content = Sanitizer::sanitize($article['content'], false, $owner_uid);

		header("Content-Type: text/html; charset=utf-8");
		header("X-Content-Type-Options: nosniff");
		header("X-Frame-Options: DENY");
		header("Referrer-Policy: no-referrer");

		?>
<!DOCTYPE html>
<html>
<head>
	<meta charset="UTF-8">
	<meta http-equiv="Content-Security-Policy" content="default-src 'none'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; img-src https: data:">
	<title><?= $title ?></title>
	<style>
		* {
			box-sizing: border-box;
		}
		body {
			font-family: Georgia, 'Times New Roman', serif;
			max-width: 800px;
			margin: 0 auto;
			padding: 40px 20px;
			color: #222;
			line-height: 1.6;
			font-size: 14px;
		}
		h1 {
			font-size: 24px;
			line-height: 1.3;
			margin-bottom: 8px;
		}
		h1 a {
			color: inherit;
			text-decoration: none;
		}
		.meta {
			color: #666;
			font-size: 13px;
			margin-bottom: 24px;
			padding-bottom: 12px;
			border-bottom: 1px solid #ddd;
		}
		.meta span + span::before {
			content: " \2022 ";
			margin: 0 6px;
		}
		.content img {
			max-width: 100%;
			height: auto;
		}
		.content a {
			color: #1a0dab;
		}
		@media print {
			body {
				padding: 0;
				font-size: 12px;
			}
			a {
				color: #000 !important;
				text-decoration: none !important;
			}
			a[href]::after {
				content: " (" attr(href) ")";
				font-size: 10px;
				color: #666;
			}
			.no-print {
				display: none !important;
			}
		}
		@media screen {
			.no-print-hint {
				background: #f5f5f5;
				border: 1px solid #ddd;
				border-radius: 4px;
				padding: 12px 16px;
				margin-bottom: 20px;
				font-family: sans-serif;
				font-size: 13px;
				color: #555;
			}
		}
	</style>
</head>
<body>
	<div class="no-print no-print-hint">
		<?= __('Der Druckdialog wird automatisch geöffnet. Sie können die Seite auch als PDF speichern.') ?>
	</div>
	<article>
		<h1>
			<?php if (!empty($article['link'])) { ?>
				<a href="<?= $link ?>"><?= $title ?></a>
			<?php } else { ?>
				<?= $title ?>
			<?php } ?>
		</h1>
		<div class="meta">
			<?php if ($feed_title) { ?><span><?= $feed_title ?></span><?php } ?>
			<?php if ($author) { ?><span><?= $author ?></span><?php } ?>
			<?php if ($updated) { ?><span><?= $updated ?></span><?php } ?>
		</div>
		<div class="content">
			<?= $content ?>
		</div>
	</article>
	<script>
		window.addEventListener('load', function() {
			setTimeout(function() { window.print(); }, 500);
		});
	</script>
</body>
</html>
		<?php
	}

	function api_version() {
		return 2;
	}
}
