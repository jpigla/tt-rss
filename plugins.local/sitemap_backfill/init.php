<?php
class Sitemap_Backfill extends Plugin {

	/** @var PluginHost $host */
	private $host;

	const MAX_ARTICLES = 200;
	const FETCH_TIMEOUT = 10;
	const TIME_BUDGET = 90;
	const TITLE_FETCH_TIMEOUT = 3;
	const TITLE_BATCH_SIZE = 20;
	const TITLE_MAX_BYTES = 32768;

	function about() {
		return [1.0,
			'Sitemap-Backfill: Lädt historische Artikel beim ersten Feed-Update via Sitemap',
			'tt-ss',
			false];
	}

	function api_version() {
		return 2;
	}

	function init($host) {
		$this->host = $host;
		// Deaktiviert: URL-Filterung noch nicht ausgereift
		// $host->add_hook($host::HOOK_FEED_FETCHED, $this);
		$host->add_hook($host::HOOK_PREFS_TAB, $this);
	}

	/**
	 * @param string $feed_data Raw-Feed-XML
	 * @param string $fetch_url Abruf-URL
	 * @param int $owner_uid Benutzer-ID
	 * @param int $feed Feed-ID
	 * @return string Modifizierte oder originale Feed-Daten
	 */
	function hook_feed_fetched($feed_data, $fetch_url = '', $owner_uid = 0, $feed = 0) {
		if (!$owner_uid || !$feed) return $feed_data;

		$feed_id = (int) $feed;

		$force = $this->is_force_queued($feed_id);

		if ($this->is_backfilled($feed_id) && !$force) {
			Debug::log("sitemap_backfill: feed $feed_id bereits backfilled, überspringe.", Debug::LOG_VERBOSE);
			return $feed_data;
		}

		if (!$force && $this->has_existing_articles($feed_id, $owner_uid)) {
			Debug::log("sitemap_backfill: feed $feed_id hat bereits Artikel, markiere als backfilled.", Debug::LOG_VERBOSE);
			$this->mark_backfilled($feed_id);
			return $feed_data;
		}

		if ($force) {
			$this->clear_force_queued($feed_id);
			Debug::log("sitemap_backfill: Erzwungener Backfill für feed $feed_id", Debug::LOG_VERBOSE);
		}

		Debug::log("sitemap_backfill: Starte Backfill für feed $feed_id", Debug::LOG_VERBOSE);

		$site_url = $this->get_site_base_url($feed_id, $fetch_url);
		if (!$site_url) {
			Debug::log("sitemap_backfill: Keine Site-URL ermittelbar für feed $feed_id", Debug::LOG_VERBOSE);
			$this->mark_backfilled($feed_id);
			return $feed_data;
		}

		$sitemap_url = $this->discover_sitemap_url($site_url);
		if (!$sitemap_url) {
			Debug::log("sitemap_backfill: Keine Sitemap für $site_url gefunden", Debug::LOG_VERBOSE);
			$this->mark_backfilled($feed_id);
			return $feed_data;
		}

		$deadline = microtime(true) + self::TIME_BUDGET;
		$urls = $this->fetch_sitemap_urls($sitemap_url, $deadline);

		if (empty($urls)) {
			Debug::log("sitemap_backfill: Sitemap leer oder nicht parsebar: $sitemap_url", Debug::LOG_VERBOSE);
			$this->mark_backfilled($feed_id);
			return $feed_data;
		}

		Debug::log("sitemap_backfill: " . count($urls) . " URLs aus Sitemap, injiziere in Feed-XML", Debug::LOG_VERBOSE);

		$feed_data = $this->inject_items_into_feed($feed_data, $urls, $deadline);
		$this->mark_backfilled($feed_id);

		return $feed_data;
	}

	// ─── State-Tracking ──────────────────────────────────────────────────────

	private function is_backfilled(int $feed_id): bool {
		$backfilled = $this->host->get_array($this, 'backfilled_feeds');
		return isset($backfilled[$feed_id]);
	}

	private function mark_backfilled(int $feed_id): void {
		$backfilled = $this->host->get_array($this, 'backfilled_feeds');
		$backfilled[$feed_id] = time();
		$this->host->set($this, 'backfilled_feeds', $backfilled);
	}

	private function is_force_queued(int $feed_id): bool {
		$queued = $this->host->get_array($this, 'force_backfill_feeds');
		return isset($queued[$feed_id]);
	}

	private function clear_force_queued(int $feed_id): void {
		$queued = $this->host->get_array($this, 'force_backfill_feeds');
		unset($queued[$feed_id]);
		$this->host->set($this, 'force_backfill_feeds', $queued);
	}

	// ─── First-Update-Erkennung ───────────────────────────────────────────────

	private function has_existing_articles(int $feed_id, int $owner_uid): bool {
		$sth = $this->pdo->prepare(
			'SELECT COUNT(*) FROM ttrss_user_entries WHERE feed_id = ? AND owner_uid = ?'
		);
		$sth->execute([$feed_id, $owner_uid]);
		return (int) $sth->fetchColumn() > 0;
	}

	// ─── Site-URL ermitteln ───────────────────────────────────────────────────

	private function get_site_base_url(int $feed_id, string $fetch_url): ?string {
		$sth = $this->pdo->prepare('SELECT site_url FROM ttrss_feeds WHERE id = ?');
		$sth->execute([$feed_id]);
		$row = $sth->fetch();

		$site_url = $row ? (string) $row['site_url'] : '';

		if ($site_url) {
			$parsed = parse_url($site_url);
			if ($parsed && isset($parsed['scheme'], $parsed['host'])) {
				return $parsed['scheme'] . '://' . $parsed['host'];
			}
		}

		// Fallback: Base-URL aus Feed-URL
		$parsed = parse_url($fetch_url);
		if ($parsed && isset($parsed['scheme'], $parsed['host'])) {
			return $parsed['scheme'] . '://' . $parsed['host'];
		}

		return null;
	}

	// ─── Sitemap-Entdeckung ───────────────────────────────────────────────────

	private function discover_sitemap_url(string $site_url): ?string {
		// 1. robots.txt prüfen
		$robots_url = rtrim($site_url, '/') . '/robots.txt';
		$robots = UrlHelper::fetch([
			'url' => $robots_url,
			'timeout' => self::FETCH_TIMEOUT,
		]);

		if ($robots) {
			foreach (explode("\n", $robots) as $line) {
				if (preg_match('/^Sitemap:\s*(.+)$/i', trim($line), $m)) {
					$url = trim($m[1]);
					// Gzipped Sitemaps in v1 überspringen
					if (!str_ends_with(strtolower($url), '.xml.gz')) {
						return $url;
					}
				}
			}
		}

		// 2. Fallback: /sitemap.xml direkt versuchen
		$sitemap_url = rtrim($site_url, '/') . '/sitemap.xml';
		$data = UrlHelper::fetch([
			'url' => $sitemap_url,
			'timeout' => self::FETCH_TIMEOUT,
		]);

		if ($data && strlen($data) > 50) {
			return $sitemap_url;
		}

		return null;
	}

	// ─── Sitemap-Parsing ──────────────────────────────────────────────────────

	/**
	 * @param float $deadline Microtime-Deadline
	 * @return array<int, array{url: string, lastmod: ?string}>
	 */
	private function fetch_sitemap_urls(string $sitemap_url, float &$deadline): array {
		if (microtime(true) >= $deadline) return [];

		$data = UrlHelper::fetch([
			'url' => $sitemap_url,
			'timeout' => min(self::FETCH_TIMEOUT, (int) ($deadline - microtime(true))),
		]);

		if (!$data) return [];

		return $this->parse_sitemap_xml($data, $deadline);
	}

	/**
	 * @param float $deadline Microtime-Deadline
	 * @return array<int, array{url: string, lastmod: ?string}>
	 */
	private function parse_sitemap_xml(string $xml, float &$deadline): array {
		libxml_use_internal_errors(true);
		$doc = simplexml_load_string($xml);

		if ($doc === false) {
			Debug::log('sitemap_backfill: Sitemap-XML konnte nicht geparst werden', Debug::LOG_VERBOSE);
			return [];
		}

		$doc->registerXPathNamespace('sm', 'http://www.sitemaps.org/schemas/sitemap/0.9');
		$root_name = $doc->getName();

		// Sitemap-Index: rekursiv Kind-Sitemaps verarbeiten
		if ($root_name === 'sitemapindex') {
			return $this->parse_sitemap_index($doc, $deadline);
		}

		// Reguläre Sitemap (urlset)
		if ($root_name === 'urlset') {
			return $this->parse_urlset($doc);
		}

		return [];
	}

	/**
	 * Prüft, ob eine Kind-Sitemap wahrscheinlich Artikel enthält (anhand des Dateinamens).
	 * Sitemaps für Kategorien, Tags, Autoren, Seiten etc. werden übersprungen.
	 */
	private function is_article_sitemap(string $url): bool {
		$basename = strtolower(basename(parse_url($url, PHP_URL_PATH) ?? ''));
		// Suffix wie "-sitemap.xml" oder "-sitemap1.xml" entfernen
		$name = preg_replace('/(-sitemap\d*|-\d+)?\.xml$/', '', $basename);

		$skip = ['category', 'categories', 'tag', 'tags', 'author', 'authors',
		         'page', 'pages', 'taxonomy', 'taxonomies', 'product', 'products',
		         'attachment', 'attachments', 'video', 'videos', 'image', 'images',
		         'media', 'archive', 'archives', 'archiv'];

		// Segmente nach Bindestrich UND Unterstrich aufteilen (z.B. post_tag, wp-sitemap-taxonomies-...)
		$segments = preg_split('/[-_]/', $name);
		foreach ($segments as $seg) {
			if (in_array($seg, $skip, true)) {
				Debug::log("sitemap_backfill: Kind-Sitemap übersprungen (Nicht-Artikel): $url", Debug::LOG_VERBOSE);
				return false;
			}
		}
		return true;
	}

	/**
	 * Prüft anhand von URL-Pfad-Heuristiken, ob eine URL wahrscheinlich ein Artikel ist.
	 */
	private function is_likely_article_url(string $url): bool {
		$path = rtrim(parse_url($url, PHP_URL_PATH) ?? '', '/');

		if ($path === '' || $path === '/') return false;

		$segments = array_values(array_filter(explode('/', $path)));
		if (empty($segments)) return false;

		$non_article = ['category', 'categories', 'tag', 'tags', 'author', 'authors',
		                'page', 'search', 'suche', 'login', 'register', 'signin', 'signup',
		                'cart', 'checkout', 'impressum', 'datenschutz', 'kontakt', 'about',
		                'contact', 'privacy', 'terms', 'cookie', 'cookies', 'sitemap',
		                'feed', 'rss', 'wp-admin', 'wp-content', 'wp-includes', 'wp-json',
		                'archive', 'archives', 'archiv'];

		// Erstes Segment prüfen — Pfade wie /category/news sind immer ausgeschlossen
		if (in_array(strtolower($segments[0]), $non_article, true)) return false;

		// Einzel-Segment-Pfade (/about, /kontakt) nur bei slug-artigen Werten akzeptieren
		if (count($segments) === 1) {
			$slug = $segments[0];
			// Slug gilt als artikel-artig: enthält Bindestriche und ist lang genug
			return str_contains($slug, '-') && strlen($slug) >= 12;
		}

		return true;
	}

	/**
	 * @param float $deadline Microtime-Deadline
	 * @return array<int, array{url: string, lastmod: ?string}>
	 */
	private function parse_sitemap_index(\SimpleXMLElement $doc, float &$deadline): array {
		$sitemaps = [];

		foreach ($doc->sitemap as $sitemap) {
			$loc = isset($sitemap->loc) ? trim((string) $sitemap->loc) : '';
			$lastmod = isset($sitemap->lastmod) ? trim((string) $sitemap->lastmod) : null;

			if ($loc && !str_ends_with(strtolower($loc), '.xml.gz') && $this->is_article_sitemap($loc)) {
				$sitemaps[] = ['url' => $loc, 'lastmod' => $lastmod];
			}
		}

		// Neueste Kind-Sitemaps zuerst
		usort($sitemaps, fn($a, $b) => strcmp($b['lastmod'] ?? '', $a['lastmod'] ?? ''));

		$results = [];
		foreach ($sitemaps as $s) {
			if (microtime(true) >= $deadline) break;
			if (count($results) >= self::MAX_ARTICLES) break;

			$child_urls = $this->fetch_sitemap_urls($s['url'], $deadline);
			$results = array_merge($results, $child_urls);
		}

		return $results;
	}

	/**
	 * @return array<int, array{url: string, lastmod: ?string}>
	 */
	private function parse_urlset(\SimpleXMLElement $doc): array {
		$results = [];

		foreach ($doc->url as $url_el) {
			$loc = isset($url_el->loc) ? trim((string) $url_el->loc) : '';
			$lastmod = isset($url_el->lastmod) ? trim((string) $url_el->lastmod) : null;

			if ($loc && $this->is_likely_article_url($loc)) {
				$results[] = ['url' => $loc, 'lastmod' => $lastmod];
			}
		}

		return $results;
	}

	// ─── Titel-Fetching (parallel) ───────────────────────────────────────────

	/**
	 * Fetcht <title>-Tags von mehreren Seiten parallel via curl_multi.
	 * Fallback pro URL auf URL-Slug wenn Fetch fehlschlägt oder Budget erschöpft.
	 *
	 * @param array<string> $urls
	 * @param float $deadline Microtime-Deadline
	 * @return array<string, string> url => title
	 */
	private function fetch_page_titles(array $urls, float &$deadline): array {
		$titles = [];
		$batches = array_chunk($urls, self::TITLE_BATCH_SIZE);

		foreach ($batches as $batch) {
			if (microtime(true) >= $deadline) break;

			$remaining = max(1, (int) ($deadline - microtime(true)));
			$timeout = min(self::TITLE_FETCH_TIMEOUT, $remaining);

			$multi = curl_multi_init();
			/** @var array<string, \CurlHandle> $handles */
			$handles = [];

			foreach ($batch as $url) {
				$ch = curl_init($url);
				if ($ch === false) continue;

				curl_setopt_array($ch, [
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_TIMEOUT        => $timeout,
					CURLOPT_FOLLOWLOCATION => true,
					CURLOPT_MAXREDIRS      => 5,
					CURLOPT_BUFFERSIZE     => self::TITLE_MAX_BYTES,
					CURLOPT_USERAGENT      => Config::get_user_agent(),
					CURLOPT_HTTPHEADER     => ['Range: bytes=0-' . (self::TITLE_MAX_BYTES - 1)],
					CURLOPT_SSL_VERIFYPEER => true,
					CURLOPT_ENCODING       => '',
				]);

				curl_multi_add_handle($multi, $ch);
				$handles[$url] = $ch;
			}

			$running = null;
			do {
				$status = curl_multi_exec($multi, $running);
				if ($status !== CURLM_OK) break;
				if ($running > 0) curl_multi_select($multi, 0.1);
			} while ($running > 0 && microtime(true) < $deadline);

			foreach ($handles as $url => $ch) {
				$html = curl_multi_getcontent($ch);
				if ($html) {
					$title = $this->extract_title_from_html($html);
					if ($title !== null) {
						$titles[$url] = $title;
					}
				}
				curl_multi_remove_handle($multi, $ch);
				curl_close($ch);
			}

			curl_multi_close($multi);
		}

		return $titles;
	}

	private function extract_title_from_html(string $html): ?string {
		$head = substr($html, 0, self::TITLE_MAX_BYTES);

		if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $head, $m)) {
			$title = html_entity_decode(trim(strip_tags($m[1])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
			$title = preg_replace('/\s+/', ' ', $title);
			$title = mb_substr(trim($title), 0, 250);
			return $title !== '' ? $title : null;
		}

		return null;
	}

	// ─── Titel-Ableitung (Fallback) ──────────────────────────────────────────

	private function title_from_url(string $url): string {
		$path = parse_url($url, PHP_URL_PATH) ?? '';
		$segments = array_filter(explode('/', $path));

		// Letztes nicht-numerisches Segment nehmen
		$title = '';
		foreach (array_reverse($segments) as $seg) {
			$seg = preg_replace('/\.\w{2,5}$/', '', $seg); // Extension entfernen
			if ($seg && !preg_match('/^\d+$/', $seg)) {
				$title = $seg;
				break;
			}
		}

		if (!$title) {
			$title = $path ?: $url;
		}

		$title = str_replace(['-', '_'], ' ', $title);
		$title = ucwords($title);
		return mb_substr($title, 0, 250);
	}

	// ─── XML-Injection ────────────────────────────────────────────────────────

	/**
	 * @param array<int, array{url: string, lastmod: ?string}> $urls
	 */
	private function inject_items_into_feed(string $feed_data, array $urls, float &$deadline): string {
		$feed_type = $this->detect_feed_type($feed_data);
		if (!$feed_type) return $feed_data;

		$existing_links = $this->extract_existing_links($feed_data);

		// Nach lastmod absteigend sortieren, nulls zuletzt
		usort($urls, function ($a, $b) {
			if ($a['lastmod'] === null && $b['lastmod'] === null) return 0;
			if ($a['lastmod'] === null) return 1;
			if ($b['lastmod'] === null) return -1;
			return strcmp($b['lastmod'], $a['lastmod']);
		});

		// Auf MAX_ARTICLES begrenzen und bereits enthaltene URLs filtern
		$urls = array_slice($urls, 0, self::MAX_ARTICLES);
		$urls = array_values(array_filter($urls, fn($e) => !in_array($e['url'], $existing_links, true)));


		// Titel parallel via curl_multi fetchen
		$url_list = array_column($urls, 'url');
		$titles = $this->fetch_page_titles($url_list, $deadline);

		$items_xml = '';
		foreach ($urls as $entry) {
			$url = $entry['url'];
			$lastmod = $entry['lastmod'];

			$title = $titles[$url] ?? $this->title_from_url($url);
			$escaped_url = htmlspecialchars($url, ENT_XML1, 'UTF-8');
			$escaped_title = htmlspecialchars($title, ENT_XML1, 'UTF-8');

			if ($feed_type === 'atom') {
				$date_str = $lastmod ? $this->to_atom_date($lastmod) : date('Y-m-d\TH:i:s\Z');
				$items_xml .= "\n<entry>\n" .
					"<title>$escaped_title</title>\n" .
					"<link href=\"$escaped_url\" />\n" .
					"<id>$escaped_url</id>\n" .
					"<updated>$date_str</updated>\n" .
					"</entry>";
			} else {
				// RSS und RDF
				$date_str = $lastmod ? $this->to_rss_date($lastmod) : date('r');
				$items_xml .= "\n<item>\n" .
					"<title>$escaped_title</title>\n" .
					"<link>$escaped_url</link>\n" .
					"<guid isPermaLink=\"true\">$escaped_url</guid>\n" .
					"<pubDate>$date_str</pubDate>\n" .
					"</item>";
			}
		}

		if (!$items_xml) return $feed_data;

		// Items vor schließendem Tag einfügen
		if ($feed_type === 'atom') {
			return str_replace('</feed>', $items_xml . "\n</feed>", $feed_data);
		} elseif ($feed_type === 'rdf') {
			return str_replace('</rdf:RDF>', $items_xml . "\n</rdf:RDF>", $feed_data);
		} else {
			return str_replace('</channel>', $items_xml . "\n</channel>", $feed_data);
		}
	}

	private function detect_feed_type(string $feed_data): ?string {
		$head = substr($feed_data, 0, 2000);
		if (str_contains($head, '<feed')) return 'atom';
		if (str_contains($head, '<rdf:RDF')) return 'rdf';
		if (str_contains($head, '<rss')) return 'rss';
		return null;
	}

	/**
	 * @return array<string>
	 */
	private function extract_existing_links(string $feed_data): array {
		libxml_use_internal_errors(true);
		$doc = new DOMDocument();
		$doc->loadXML($feed_data);

		$xpath = new DOMXPath($doc);
		$xpath->registerNamespace('atom', 'http://www.w3.org/2005/Atom');

		$links = [];

		// RSS/RDF: //item/link
		foreach ($xpath->query('//item/link') ?: [] as $node) {
			$val = trim($node->textContent);
			if ($val) $links[] = $val;
		}

		// Atom: //atom:entry/atom:link[@href]
		foreach ($xpath->query('//atom:entry/atom:link/@href') ?: [] as $attr) {
			$val = trim($attr->value);
			if ($val) $links[] = $val;
		}

		return array_unique($links);
	}

	// ─── Prefs-Tab ───────────────────────────────────────────────────────────

	function hook_prefs_tab($args): void {
		if ($args != 'prefPrefs') return;

		$backfilled = $this->host->get_array($this, 'backfilled_feeds');
		$queued     = $this->host->get_array($this, 'force_backfill_feeds');

		$sth = $this->pdo->prepare(
			'SELECT COUNT(*) FROM ttrss_feeds WHERE owner_uid = ?'
		);
		$sth->execute([$_SESSION['uid']]);
		$total_feeds = (int) $sth->fetchColumn();

		$backfilled_count = count($backfilled);
		$queued_count     = count($queued);

		?>
		<div dojoType="dijit.layout.AccordionPane"
			title="<i class='material-icons'>history</i> <?= __('Sitemap-Backfill') ?>">

			<p style="margin-bottom: 12px">
				<?= sprintf(
					__('%d von %d Feeds bereits backfilled. %d Feeds in Warteschlange (werden beim nächsten Update verarbeitet).'),
					$backfilled_count,
					$total_feeds,
					$queued_count
				) ?>
			</p>

			<form dojoType="dijit.form.Form">
				<?= \Controls\pluginhandler_tags($this, 'queue_all_backfill') ?>

				<script type="dojo/method" event="onSubmit" args="evt">
					evt.preventDefault();
					if (this.validate()) {
						Notify.progress('<?= __('Feeds werden vorgemerkt…') ?>', true);
						xhr.post("backend.php", this.getValues(), (reply) => {
							Notify.info(reply);
						});
					}
				</script>

				<button dojoType="dijit.form.Button" type="submit" class="alt-primary">
					<?= __('Alle bestehenden Feeds für Backfill vormerken') ?>
				</button>
			</form>

			<?php if (!empty($queued)): ?>
			<form dojoType="dijit.form.Form" style="margin-top: 8px">
				<?= \Controls\pluginhandler_tags($this, 'clear_queue') ?>

				<script type="dojo/method" event="onSubmit" args="evt">
					evt.preventDefault();
					if (this.validate()) {
						xhr.post("backend.php", this.getValues(), (reply) => {
							Notify.info(reply);
						});
					}
				</script>

				<button dojoType="dijit.form.Button" type="submit">
					<?= __('Warteschlange leeren') ?>
				</button>
			</form>
			<?php endif; ?>

		</div>
		<?php
	}

	function queue_all_backfill(): void {
		$sth = $this->pdo->prepare(
			'SELECT id FROM ttrss_feeds WHERE owner_uid = ?'
		);
		$sth->execute([$_SESSION['uid']]);
		$feed_ids = $sth->fetchAll(\PDO::FETCH_COLUMN);

		// Alle aus backfilled entfernen und in force-Queue eintragen
		$queued = [];
		foreach ($feed_ids as $fid) {
			$queued[(int) $fid] = true;
		}
		$this->host->set($this, 'force_backfill_feeds', $queued);
		$this->host->set($this, 'backfilled_feeds', []);

		echo sprintf(__('%d Feeds vorgemerkt. Backfill läuft beim nächsten Update-Zyklus.'), count($feed_ids));
	}

	function clear_queue(): void {
		$this->host->set($this, 'force_backfill_feeds', []);
		echo __('Warteschlange geleert.');
	}

	// ─── Datumskonvertierung ──────────────────────────────────────────────────

	private function to_rss_date(string $lastmod): string {
		$ts = strtotime($lastmod);
		if ($ts === false) return date('r');
		return date('r', $ts);
	}

	private function to_atom_date(string $lastmod): string {
		$ts = strtotime($lastmod);
		if ($ts === false) return date('Y-m-d\TH:i:s\Z');
		return gmdate('Y-m-d\TH:i:s\Z', $ts);
	}
}
