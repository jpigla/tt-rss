<?php
class Feed_Autodetect extends Plugin {

	/** @var PluginHost $host */
	private $host;

	/** @var array<int, string> Häufige Feed-Pfade */
	const COMMON_FEED_PATHS = [
		'/feed',
		'/feed/',
		'/rss',
		'/rss/',
		'/rss.xml',
		'/feed.xml',
		'/atom.xml',
		'/index.xml',
		'/feeds/posts/default',
		'/blog/feed',
		'/blog/rss',
		'/.rss',
		'/feed/rss',
		'/feed/atom',
	];

	function about() {
		return [1.0,
			"Erweiterte Feed-Erkennung: Durchsucht Webseiten und gängige Pfade nach RSS/Atom-Feeds",
			"tt-ss",
			false];
	}

	/** @var array<string> HTML5-Boolean-Attribute, die in XML einen Wert brauchen */
	const HTML5_BOOLEAN_ATTRS = [
		'crossorigin', 'async', 'defer', 'nomodule', 'hidden', 'disabled',
		'checked', 'selected', 'readonly', 'required', 'autofocus',
		'autoplay', 'controls', 'loop', 'muted', 'default', 'allowfullscreen',
		'formnovalidate', 'novalidate', 'open', 'reversed', 'scoped',
		'typemustmatch', 'download', 'inert', 'itemscope',
	];

	function init($host) {
		$this->host = $host;

		$host->add_hook($host::HOOK_PREFS_TAB, $this);
		$host->add_hook($host::HOOK_FEED_FETCHED, $this);
		$host->add_hook($host::HOOK_PRE_SUBSCRIBE, $this);
	}

	function hook_prefs_tab($args) {
		if ($args != "prefFeeds") return;

		?>
		<div dojoType="dijit.layout.AccordionPane"
			title="<i class='material-icons'>rss_feed</i> <?= __('Feed-Erkennung') ?>">

			<?= format_notice(__("Gib eine Website-URL ein, um automatisch verfügbare RSS/Atom-Feeds zu finden. Es werden sowohl Link-Tags im HTML als auch gängige Feed-Pfade geprüft.")) ?>

			<form dojoType="dijit.form.Form" id="feed_autodetect_form">

				<?= \Controls\pluginhandler_tags($this, "detect_feeds") ?>

				<script type="dojo/method" event="onSubmit" args="evt">
					evt.preventDefault();
					if (this.validate()) {
						Notify.progress('Suche Feeds...', true);
						xhr.post("backend.php", this.getValues(), (reply) => {
							Notify.close();
							const el = document.getElementById('feed-detect-results');
							if (el) el.innerHTML = reply;
						});
					}
				</script>

				<fieldset>
					<label><?= __('Website-URL:') ?></label>
					<input type="text" dojoType="dijit.form.ValidationTextBox"
						name="detect_url" required="true"
						style="width: 400px"
						placeholder="https://example.com">
				</fieldset>

				<hr/>
				<?= \Controls\submit_tag(__("Feeds suchen")) ?>
			</form>

			<div id="feed-detect-results" style="margin-top: 16px;"></div>
		</div>
		<?php
	}

	/**
	 * Hook: Feed-Inhalt beim regulären Update bereinigen.
	 */
	function hook_feed_fetched($feed_data, $fetch_url, $owner_uid, $feed) {
		return $this->sanitize_feed_xml($feed_data);
	}

	/**
	 * Hook: Vor dem Abonnieren prüfen ob die URL eine HTML-Seite ist
	 * und ggf. die echte Feed-URL ermitteln.
	 */
	function hook_pre_subscribe(&$url, $auth_login, $auth_pass) {
		$html = UrlHelper::fetch(['url' => $url, 'timeout' => 15]);
		if ($html === false) return false;

		// Nur weitermachen wenn es HTML ist
		if (!preg_match('/<html|DOCTYPE html/i', substr($html, 0, 8192))) return false;

		// Feed-URLs aus HTML-Link-Tags extrahieren
		$parsed = parse_url($url);
		$base_url = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '');

		$feed_urls = [];
		$this->find_link_feeds($html, $base_url, $url, $feed_urls);

		// Auch gängige Pfade prüfen wenn keine Link-Tags gefunden
		if (empty($feed_urls)) {
			foreach (self::COMMON_FEED_PATHS as $path) {
				$feed_url = $base_url . $path;
				$result = UrlHelper::fetch(['url' => $feed_url, 'timeout' => 10]);
				if ($result !== false && $this->looks_like_feed($result)) {
					$url = $feed_url;
					return false;
				}
			}
		}

		if (count($feed_urls) === 1) {
			$url = $feed_urls[0]['url'];
		}

		return false;
	}

	/**
	 * Repariert HTML5-Boolean-Attribute ohne Wert im Feed-XML.
	 * Arbeitet nur innerhalb von HTML/XML-Tags, nicht im Fließtext.
	 */
	private function sanitize_feed_xml(string $data): string {
		if (empty($data)) return $data;

		$attrs = implode('|', self::HTML5_BOOLEAN_ATTRS);

		// Matcht komplette HTML/XML-Tags und repariert Boolean-Attribute nur darin
		return preg_replace_callback(
			'/<[a-z][a-z0-9]*\b[^>]*>/i',
			function ($match) use ($attrs) {
				return preg_replace(
					'/\s(' . $attrs . ')(?=[\s\/>])(?!\s*=)/i',
					' $1="$1"',
					$match[0]
				) ?? $match[0];
			},
			$data
		) ?? $data;
	}

	/**
	 * Feeds für eine URL erkennen.
	 */
	function detect_feeds(): void {
		$url = trim(clean($_POST['detect_url'] ?? ''));

		if (empty($url)) {
			echo "<p class='text-muted'>" . __("Bitte eine URL angeben.") . "</p>";
			return;
		}

		if (!filter_var($url, FILTER_VALIDATE_URL)) {
			echo "<p class='text-muted'>" . __("Ungültige URL.") . "</p>";
			return;
		}

		$parsed = parse_url($url);
		$base_url = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '');

		$feeds = [];

		// 1. HTML der Seite abrufen und Link-Tags suchen
		$html = UrlHelper::fetch(['url' => $url, 'timeout' => 15]);
		if ($html !== false) {
			$this->find_link_feeds($html, $base_url, $url, $feeds);
		}

		// 2. Gängige Feed-Pfade prüfen
		foreach (self::COMMON_FEED_PATHS as $path) {
			$feed_url = $base_url . $path;

			// Nicht testen wenn bereits gefunden
			$already_found = false;
			foreach ($feeds as $f) {
				if ($f['url'] === $feed_url) {
					$already_found = true;
					break;
				}
			}
			if ($already_found) continue;

			$result = UrlHelper::fetch(['url' => $feed_url, 'timeout' => 10]);
			if ($result !== false && $this->looks_like_feed($result)) {
				$title = $this->extract_feed_title($result);
				$feeds[] = [
					'url' => $feed_url,
					'title' => $title ?: $path,
					'source' => __('Pfad-Erkennung')
				];
			}
		}

		// Ergebnisse anzeigen
		if (empty($feeds)) {
			echo "<p class='text-muted'>" . __("Keine Feeds gefunden.") . "</p>";
			return;
		}

		echo "<h4>" . sprintf(__("%d Feed(s) gefunden:"), count($feeds)) . "</h4>";
		echo "<table width='100%' style='border-collapse: collapse;'>";
		echo "<thead><tr>"
			. "<th style='padding: 6px 10px; text-align: left;'>" . __('Titel') . "</th>"
			. "<th style='padding: 6px 10px; text-align: left;'>" . __('URL') . "</th>"
			. "<th style='padding: 6px 10px; text-align: left;'>" . __('Quelle') . "</th>"
			. "<th style='padding: 6px 10px; text-align: left;'>" . __('Aktion') . "</th>"
			. "</tr></thead><tbody>";

		foreach ($feeds as $feed) {
			$escaped_url = htmlspecialchars($feed['url']);
			$escaped_title = htmlspecialchars($feed['title']);
			$escaped_source = htmlspecialchars($feed['source']);

			echo "<tr style='border-bottom: 1px solid var(--border-color, #ddd);'>"
				. "<td style='padding: 6px 10px;'>{$escaped_title}</td>"
				. "<td style='padding: 6px 10px;'><a href='{$escaped_url}' target='_blank' rel='noopener'>{$escaped_url}</a></td>"
				. "<td style='padding: 6px 10px; color: var(--text-muted);'>{$escaped_source}</td>"
				. "<td style='padding: 6px 10px;'>"
				. "<form dojoType='dijit.form.Form' style='display: inline;'>"
				. \Controls\pluginhandler_tags($this, "subscribe_feed")
				. "<input type='hidden' name='feed_url' value='{$escaped_url}'>"
				. "<button dojoType='dijit.form.Button' type='submit'>"
				. "<i class='material-icons'>add</i> " . __('Abonnieren')
				. "</button>"
				. "<script type='dojo/method' event='onSubmit' args='evt'>"
				. "evt.preventDefault();"
				. "xhr.post('backend.php', this.getValues(), (reply) => { Notify.info(reply); });"
				. "</script>"
				. "</form>"
				. "</td></tr>";
		}

		echo "</tbody></table>";
	}

	/**
	 * Feed abonnieren.
	 */
	function subscribe_feed(): void {
		$url = trim(clean($_POST['feed_url'] ?? ''));

		if (empty($url)) {
			echo __("Keine Feed-URL angegeben.");
			return;
		}

		try {
			$result = Feeds::_subscribe($url, 0);
			if ($result >= 0) {
				echo sprintf(__("Feed '%s' erfolgreich abonniert."), htmlspecialchars($url));
			} else {
				echo sprintf(__("Fehler beim Abonnieren: Code %d"), $result);
			}
		} catch (Exception $e) {
			echo sprintf(__("Fehler: %s"), htmlspecialchars($e->getMessage()));
		}
	}

	/**
	 * Sucht Feed-Links im HTML via Link-Tags.
	 * @param string $html
	 * @param string $base_url
	 * @param string $page_url
	 * @param array<int, array{url: string, title: string, source: string}> &$feeds
	 */
	private function find_link_feeds(string $html, string $base_url, string $page_url, array &$feeds): void {
		// <link rel="alternate" type="application/rss+xml" ...>
		// <link rel="alternate" type="application/atom+xml" ...>
		$pattern = '/<link[^>]+rel=["\']alternate["\'][^>]*>/i';

		if (!preg_match_all($pattern, $html, $matches)) return;

		foreach ($matches[0] as $tag) {
			// Typ prüfen
			if (!preg_match('/type=["\']application\/(rss|atom)\+xml["\']/i', $tag)) continue;

			// URL extrahieren
			if (!preg_match('/href=["\']([^"\']+)["\']/i', $tag, $href_match)) continue;

			$feed_url = $href_match[1];

			// Relative URL auflösen
			if (str_starts_with($feed_url, '/')) {
				$feed_url = $base_url . $feed_url;
			} elseif (!str_starts_with($feed_url, 'http')) {
				$feed_url = rtrim($page_url, '/') . '/' . $feed_url;
			}

			// Titel extrahieren
			$title = '';
			if (preg_match('/title=["\']([^"\']+)["\']/i', $tag, $title_match)) {
				$title = html_entity_decode($title_match[1], ENT_QUOTES, 'UTF-8');
			}

			$feeds[] = [
				'url' => $feed_url,
				'title' => $title ?: parse_url($feed_url, PHP_URL_PATH),
				'source' => __('HTML-Link-Tag')
			];
		}
	}

	/**
	 * Prüft ob der Inhalt wie ein RSS/Atom-Feed aussieht.
	 * @param string $content
	 * @return bool
	 */
	private function looks_like_feed(string $content): bool {
		$content = ltrim($content);
		// XML-Deklaration oder typische Feed-Root-Elemente
		return (bool) preg_match('/^(<\?xml|<rss|<feed|<RDF)/i', $content);
	}

	/**
	 * Extrahiert den Titel aus einem Feed.
	 * @param string $content
	 * @return string
	 */
	private function extract_feed_title(string $content): string {
		if (preg_match('/<title[^>]*>([^<]+)<\/title>/i', $content, $m)) {
			return html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
		}
		return '';
	}

	function api_version() {
		return 2;
	}
}
