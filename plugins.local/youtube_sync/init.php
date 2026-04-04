<?php
class Youtube_Sync extends Plugin {

	/** @var PluginHost $host */
	private $host;

	function about() {
		return [1.0,
			"Importiert YouTube-Abonnements aus OPML und bettet Videos direkt in Artikeln ein",
			"tt-ss",
			false];
	}

	function init($host) {
		$this->host = $host;

		$host->add_hook($host::HOOK_RENDER_ARTICLE_CDM, $this);
		$host->add_hook($host::HOOK_RENDER_ARTICLE, $this);
		$host->add_hook($host::HOOK_PREFS_TAB, $this);
	}

	function get_js() {
		return file_get_contents(__DIR__ . "/youtube_sync.js");
	}

	function get_css() {
		return "
			.yt-embed-container {
				position: relative;
				padding-bottom: 56.25%;
				height: 0;
				overflow: hidden;
				max-width: 640px;
				margin: 12px 0;
			}
			.yt-embed-container iframe {
				position: absolute;
				top: 0;
				left: 0;
				width: 100%;
				height: 100%;
				border: 0;
				border-radius: 8px;
			}
		";
	}

	/**
	 * Prüft ob ein Artikel von einem YouTube-Feed stammt und extrahiert die Video-ID.
	 * @param array<string, mixed> $article
	 * @return string|null Video-ID oder null
	 */
	private function get_youtube_video_id(array $article): ?string {
		$link = $article['link'] ?? '';

		// YouTube-Video-URL-Muster
		if (preg_match('/(?:youtube\.com\/watch\?v=|youtu\.be\/)([a-zA-Z0-9_-]{11})/', $link, $m)) {
			return $m[1];
		}

		// Auch im Content suchen
		$content = $article['content'] ?? '';
		if (preg_match('/(?:youtube\.com\/watch\?v=|youtu\.be\/)([a-zA-Z0-9_-]{11})/', $content, $m)) {
			return $m[1];
		}

		return null;
	}

	/**
	 * Bettet YouTube-Video in Artikel ein.
	 * @param array<string, mixed> $article
	 * @return array<string, mixed>
	 */
	private function embed_video(array $article): array {
		$video_id = $this->get_youtube_video_id($article);
		if (!$video_id) return $article;

		$embed_html = '<div class="yt-embed-container">'
			. '<iframe src="https://www.youtube-nocookie.com/embed/' . htmlspecialchars($video_id)
			. '" allowfullscreen loading="lazy" '
			. 'allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture">'
			. '</iframe></div>';

		// Video-Embed vor dem Content einfügen
		$article['content'] = $embed_html . ($article['content'] ?? '');

		return $article;
	}

	function hook_render_article_cdm($article) {
		return $this->embed_video($article);
	}

	function hook_render_article($article) {
		return $this->embed_video($article);
	}

	function hook_prefs_tab($args) {
		if ($args != "prefFeeds") return;

		$import_count = $this->host->get($this, "last_import_count", 0);
		$import_date = $this->host->get($this, "last_import_date", "");

		?>
		<div dojoType="dijit.layout.AccordionPane"
			title="<i class='material-icons'>smart_display</i> <?= __('YouTube-Sync') ?>">

			<?= format_notice(__("Importiere deine YouTube-Abonnements aus einem Google-Takeout-OPML-Export. Die Kanäle werden als RSS-Feeds abonniert.")) ?>

			<?php if (!empty($import_date)) { ?>
				<p class="text-muted">
					<?= sprintf(__("Letzter Import: %s (%d Kanäle)"), htmlspecialchars($import_date), $import_count) ?>
				</p>
			<?php } ?>

			<form dojoType="dijit.form.Form" id="yt_sync_import_form"
				enctype="multipart/form-data" method="post">

				<?= \Controls\pluginhandler_tags($this, "import_opml") ?>

				<fieldset>
					<label><?= __('OPML-Datei:') ?></label>
					<input type="file" name="opml_file" id="yt_opml_file"
						accept=".opml,.xml"
						required>
				</fieldset>

				<fieldset>
					<label><?= __('Kategorie:') ?></label>
					<input type="text" dojoType="dijit.form.TextBox"
						name="yt_category"
						style="width: 200px"
						value="YouTube"
						placeholder="YouTube">
				</fieldset>

				<hr/>

				<button dojoType="dijit.form.Button" type="button"
					onclick="Plugins.Youtube_Sync.importOPML()">
					<i class="material-icons">upload</i> <?= __('OPML importieren') ?>
				</button>
			</form>
		</div>
		<?php
	}

	/**
	 * OPML-Datei importieren und YouTube-Kanäle abonnieren.
	 */
	function import_opml(): void {
		if (empty($_FILES['opml_file']['tmp_name'])) {
			echo __("Keine Datei hochgeladen.");
			return;
		}

		$content = file_get_contents($_FILES['opml_file']['tmp_name']);
		if (empty($content)) {
			echo __("Datei ist leer.");
			return;
		}

		$category = trim(clean($_POST['yt_category'] ?? 'YouTube'));
		if (empty($category)) $category = 'YouTube';

		// Kategorie finden oder erstellen
		$cat_id = $this->get_or_create_category($category);

		// OPML parsen
		$prev = libxml_use_internal_errors(true);
		$xml = simplexml_load_string($content);
		libxml_use_internal_errors($prev);

		if ($xml === false) {
			echo __("Ungültiges OPML-Format.");
			return;
		}

		$feed_urls = [];
		$this->collect_feed_urls($xml, $feed_urls);

		if (empty($feed_urls)) {
			echo __("Keine Feed-URLs in der OPML-Datei gefunden.");
			return;
		}

		$subscribed = 0;
		$errors = 0;

		foreach ($feed_urls as $url) {
			// Nur YouTube-URLs oder alle URLs abonnieren
			try {
				$result = Feeds::_subscribe($url, $cat_id);
				if ($result >= 0) {
					$subscribed++;
				}
			} catch (Exception $e) {
				$errors++;
			}
		}

		$this->host->set($this, "last_import_count", $subscribed);
		$this->host->set($this, "last_import_date", date('d.m.Y H:i'));

		echo sprintf(
			__("%d Feeds abonniert, %d Fehler, %d URLs gesamt."),
			$subscribed, $errors, count($feed_urls)
		);
	}

	/**
	 * Sammelt Feed-URLs aus OPML-XML rekursiv.
	 * @param \SimpleXMLElement $xml
	 * @param array<int, string> &$urls
	 */
	private function collect_feed_urls($xml, array &$urls): void {
		if (isset($xml->body)) {
			$this->collect_feed_urls_from_outlines($xml->body, $urls);
		}
		if (isset($xml->outline)) {
			$this->collect_feed_urls_from_outlines($xml, $urls);
		}
	}

	/**
	 * @param \SimpleXMLElement $parent
	 * @param array<int, string> &$urls
	 */
	private function collect_feed_urls_from_outlines($parent, array &$urls): void {
		foreach ($parent->children() as $child) {
			if ($child->getName() === 'outline') {
				$xmlUrl = (string) ($child['xmlUrl'] ?? '');
				if (!empty($xmlUrl)) {
					$urls[] = $xmlUrl;
				}
				// Rekursiv in Unter-Outlines suchen
				$this->collect_feed_urls_from_outlines($child, $urls);
			}
		}
	}

	/**
	 * Kategorie finden oder erstellen.
	 * @param string $title
	 * @return int Kategorie-ID (0 für unkategorisiert)
	 */
	private function get_or_create_category(string $title): int {
		$owner_uid = $_SESSION['uid'];

		$sth = $this->pdo->prepare("SELECT id FROM ttrss_feed_categories
			WHERE owner_uid = ? AND title = ?");
		$sth->execute([$owner_uid, $title]);
		$row = $sth->fetch();

		if ($row) {
			return (int) $row['id'];
		}

		$sth = $this->pdo->prepare("INSERT INTO ttrss_feed_categories
			(owner_uid, title) VALUES (?, ?)");
		$sth->execute([$owner_uid, $title]);

		return (int) $this->pdo->lastInsertId("ttrss_feed_categories_id_seq");
	}

	function api_version() {
		return 2;
	}
}
