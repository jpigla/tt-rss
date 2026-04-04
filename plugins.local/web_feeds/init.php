<?php
class Web_Feeds extends Plugin {

	/** @var PluginHost $host */
	private $host;

	function about() {
		return [1.0,
			"Webseiten ohne RSS-Feed abonnieren mittels CSS-Selektoren",
			"admin",
			false];
	}

	function init($host) {
		$this->host = $host;

		$host->add_hook($host::HOOK_FETCH_FEED, $this);
		$host->add_hook($host::HOOK_PREFS_EDIT_FEED, $this);
		$host->add_hook($host::HOOK_PREFS_SAVE_FEED, $this);
		$host->add_hook($host::HOOK_PREFS_TAB, $this);

		$m = new Db_Migrations();
		$m->initialize_for_plugin($this);
		$m->migrate();
	}

	function get_js() {
		return file_get_contents(__DIR__ . "/web_feeds.js");
	}

	function get_css() {
		return file_get_contents(__DIR__ . "/web_feeds.css");
	}

	/**
	 * Lade die Konfiguration für einen bestimmten Feed.
	 * @return array<string,string>|false
	 */
	private function get_config(int $feed_id, int $owner_uid) {
		$sth = $this->pdo->prepare("SELECT * FROM ttrss_plugin_web_feeds_config
			WHERE feed_id = ? AND owner_uid = ?");
		$sth->execute([$feed_id, $owner_uid]);
		return $sth->fetch();
	}

	function hook_fetch_feed($feed_data, $fetch_url, $owner_uid, $feed, $last_article_timestamp, $auth_login, $auth_pass) {
		$config = $this->get_config($feed, $owner_uid);

		if (!$config || empty($config['list_selector'])) {
			return $feed_data;
		}

		$html = UrlHelper::fetch(['url' => $fetch_url, 'timeout' => 15]);

		if (!$html) {
			return $feed_data;
		}

		$items = $this->scrape_items($html, $config, $fetch_url);

		if (empty($items)) {
			return $feed_data;
		}

		return $this->generate_rss($items, $fetch_url);
	}

	/**
	 * HTML mit den konfigurierten Selektoren auswerten.
	 * @return array<int, array<string, string>>
	 */
	private function scrape_items(string $html, array $config, string $base_url): array {
		$items = [];

		libxml_use_internal_errors(true);
		$doc = new DOMDocument();
		$doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR);
		libxml_clear_errors();

		$xpath = new DOMXPath($doc);

		$list_selector = $this->css_to_xpath($config['list_selector']);
		$nodes = $xpath->query($list_selector);

		if ($nodes === false || $nodes->length === 0) {
			return $items;
		}

		foreach ($nodes as $node) {
			$item = [
				'title' => '',
				'link' => '',
				'content' => '',
				'date' => date('r'),
			];

			if (!empty($config['title_selector'])) {
				$title_nodes = $xpath->query($this->css_to_xpath($config['title_selector']), $node);
				if ($title_nodes && $title_nodes->length > 0) {
					$item['title'] = trim($title_nodes->item(0)->textContent);
				}
			}

			if (!empty($config['link_selector'])) {
				$link_nodes = $xpath->query($this->css_to_xpath($config['link_selector']), $node);
				if ($link_nodes && $link_nodes->length > 0) {
					$link_node = $link_nodes->item(0);
					$href = $link_node->getAttribute('href');
					if ($href) {
						$item['link'] = $this->make_absolute_url($href, $base_url);
					}
				}
			}

			if (!empty($config['content_selector'])) {
				$content_nodes = $xpath->query($this->css_to_xpath($config['content_selector']), $node);
				if ($content_nodes && $content_nodes->length > 0) {
					$item['content'] = $doc->saveHTML($content_nodes->item(0));
				}
			}

			if (!empty($config['date_selector'])) {
				$date_nodes = $xpath->query($this->css_to_xpath($config['date_selector']), $node);
				if ($date_nodes && $date_nodes->length > 0) {
					$date_text = trim($date_nodes->item(0)->textContent);
					$ts = strtotime($date_text);
					if ($ts !== false) {
						$item['date'] = date('r', $ts);
					}
				}
			}

			if (!empty($item['title']) || !empty($item['content'])) {
				$items[] = $item;
			}
		}

		return $items;
	}

	/**
	 * Einfache CSS-zu-XPath-Umwandlung für gängige Selektoren.
	 */
	private function css_to_xpath(string $css): string {
		$css = trim($css);

		// Wenn es bereits ein XPath ist (beginnt mit / oder ./), direkt zurückgeben
		if (str_starts_with($css, '/') || str_starts_with($css, './')) {
			return $css;
		}

		$xpath = './/' ;
		$parts = preg_split('/\s+/', $css);

		$segments = [];
		foreach ($parts as $part) {
			if (preg_match('/^(\w+)?#([\w-]+)$/', $part, $m)) {
				// element#id oder #id
				$tag = $m[1] ?: '*';
				$segments[] = "{$tag}[@id='{$m[2]}']";
			} elseif (preg_match('/^(\w+)?\.([\w-]+)$/', $part, $m)) {
				// element.class oder .class
				$tag = $m[1] ?: '*';
				$segments[] = "{$tag}[contains(concat(' ', normalize-space(@class), ' '), ' {$m[2]} ')]";
			} elseif (preg_match('/^(\w+)\[(\w+)="([^"]+)"\]$/', $part, $m)) {
				// element[attr="value"]
				$segments[] = "{$m[1]}[@{$m[2]}='{$m[3]}']";
			} elseif (preg_match('/^[\w-]+$/', $part)) {
				$segments[] = $part;
			} else {
				$segments[] = $part;
			}
		}

		return $xpath . implode('//', $segments);
	}

	private function make_absolute_url(string $url, string $base): string {
		if (preg_match('#^https?://#', $url)) {
			return $url;
		}

		$parsed = parse_url($base);
		$scheme = $parsed['scheme'] ?? 'https';
		$host = $parsed['host'] ?? '';

		if (str_starts_with($url, '//')) {
			return $scheme . ':' . $url;
		}

		if (str_starts_with($url, '/')) {
			return $scheme . '://' . $host . $url;
		}

		$base_path = $parsed['path'] ?? '/';
		$base_dir = substr($base_path, 0, strrpos($base_path, '/') + 1);

		return $scheme . '://' . $host . $base_dir . $url;
	}

	/**
	 * RSS-XML aus den gescrapten Einträgen erzeugen.
	 */
	private function generate_rss(array $items, string $url): string {
		$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<rss version="2.0"><channel>';
		$xml .= '<title>Web Feed: ' . htmlspecialchars($url) . '</title>';
		$xml .= '<link>' . htmlspecialchars($url) . '</link>';
		$xml .= '<description>Automatisch generierter Feed von ' . htmlspecialchars($url) . '</description>';

		foreach ($items as $item) {
			$xml .= '<item>';
			$xml .= '<title>' . htmlspecialchars($item['title']) . '</title>';
			$xml .= '<link>' . htmlspecialchars($item['link']) . '</link>';
			$xml .= '<description><![CDATA[' . ($item['content'] ?: $item['title']) . ']]></description>';
			$xml .= '<pubDate>' . $item['date'] . '</pubDate>';
			$xml .= '<guid>' . htmlspecialchars($item['link'] ?: md5($item['title'] . $item['date'])) . '</guid>';
			$xml .= '</item>';
		}

		$xml .= '</channel></rss>';
		return $xml;
	}

	function hook_prefs_edit_feed($feed_id) {
		$config = $this->get_config($feed_id, $_SESSION['uid']);

		?>
		<div class="web-feeds-config">
			<header><?= __('Web Feed Selektoren') ?></header>
			<section>
				<fieldset>
					<label>Liste (Container):</label>
					<input dojoType="dijit.form.TextBox"
						id="web_feeds_list_selector"
						name="web_feeds_list_selector"
						value="<?= htmlspecialchars($config['list_selector'] ?? '') ?>">
				</fieldset>
				<fieldset>
					<label>Titel:</label>
					<input dojoType="dijit.form.TextBox"
						name="web_feeds_title_selector"
						id="web_feeds_title_selector"
						value="<?= htmlspecialchars($config['title_selector'] ?? '') ?>">
				</fieldset>
				<fieldset>
					<label>Link:</label>
					<input dojoType="dijit.form.TextBox"
						name="web_feeds_link_selector"
						value="<?= htmlspecialchars($config['link_selector'] ?? '') ?>">
				</fieldset>
				<fieldset>
					<label>Inhalt:</label>
					<input dojoType="dijit.form.TextBox"
						name="web_feeds_content_selector"
						value="<?= htmlspecialchars($config['content_selector'] ?? '') ?>">
				</fieldset>
				<fieldset>
					<label>Datum:</label>
					<input dojoType="dijit.form.TextBox"
						name="web_feeds_date_selector"
						value="<?= htmlspecialchars($config['date_selector'] ?? '') ?>">
				</fieldset>
				<p class="text-muted small">
					CSS-Selektoren oder XPath-Ausdrücke eingeben.
					Der Listen-Selektor wählt die einzelnen Einträge aus, die übrigen Selektoren werden relativ dazu ausgewertet.
				</p>
			</section>
		</div>
		<?php
	}

	function hook_prefs_save_feed($feed_id) {
		$list_selector = clean($_POST['web_feeds_list_selector'] ?? '');
		$title_selector = clean($_POST['web_feeds_title_selector'] ?? '');
		$link_selector = clean($_POST['web_feeds_link_selector'] ?? '');
		$content_selector = clean($_POST['web_feeds_content_selector'] ?? '');
		$date_selector = clean($_POST['web_feeds_date_selector'] ?? '');

		$config = $this->get_config($feed_id, $_SESSION['uid']);

		if ($config) {
			$sth = $this->pdo->prepare("UPDATE ttrss_plugin_web_feeds_config
				SET list_selector = ?, title_selector = ?, link_selector = ?,
					content_selector = ?, date_selector = ?
				WHERE feed_id = ? AND owner_uid = ?");
			$sth->execute([$list_selector, $title_selector, $link_selector,
				$content_selector, $date_selector, $feed_id, $_SESSION['uid']]);
		} else {
			if (!empty($list_selector)) {
				$sth = $this->pdo->prepare("INSERT INTO ttrss_plugin_web_feeds_config
					(feed_id, owner_uid, list_selector, title_selector, link_selector, content_selector, date_selector)
					VALUES (?, ?, ?, ?, ?, ?, ?)");
				$sth->execute([$feed_id, $_SESSION['uid'], $list_selector, $title_selector,
					$link_selector, $content_selector, $date_selector]);
			}
		}
	}

	function hook_prefs_tab($args) {
		if ($args != "prefFeeds") return;

		$sth = $this->pdo->prepare("SELECT wf.*, f.title AS feed_title, f.feed_url
			FROM ttrss_plugin_web_feeds_config wf
			LEFT JOIN ttrss_feeds f ON f.id = wf.feed_id
			WHERE wf.owner_uid = ?
			ORDER BY f.title");
		$sth->execute([$_SESSION['uid']]);

		?>
		<div dojoType="dijit.layout.AccordionPane"
			title="<i class='material-icons'>language</i> <?= __('Web Feeds') ?>">

			<h3><?= __('Konfigurierte Web Feeds') ?></h3>
			<p><?= __('Webseiten, die per CSS-Selektoren als Feed ausgelesen werden. Die Selektoren können in den Feed-Einstellungen konfiguriert werden.') ?></p>

			<div class="web-feeds-list">
				<table>
					<thead>
						<tr>
							<th><?= __('Feed') ?></th>
							<th><?= __('Listen-Selektor') ?></th>
							<th><?= __('Titel-Selektor') ?></th>
						</tr>
					</thead>
					<tbody>
					<?php
					$count = 0;
					while ($row = $sth->fetch()) {
						$count++;
						?>
						<tr>
							<td><?= htmlspecialchars($row['feed_title'] ?: $row['feed_url'] ?: '#' . $row['feed_id']) ?></td>
							<td><code><?= htmlspecialchars($row['list_selector']) ?></code></td>
							<td><code><?= htmlspecialchars($row['title_selector']) ?></code></td>
						</tr>
						<?php
					}

					if ($count === 0) {
						?>
						<tr>
							<td colspan="3"><?= __('Noch keine Web Feeds konfiguriert.') ?></td>
						</tr>
						<?php
					}
					?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	function preview() : void {
		$feed_id = (int)clean($_REQUEST['feed_id'] ?? 0);
		$list_selector = clean($_REQUEST['list_selector'] ?? '');
		$title_selector = clean($_REQUEST['title_selector'] ?? '');

		if (!$feed_id || empty($list_selector)) {
			print json_encode(['error' => 'Feed-ID und Listen-Selektor erforderlich.']);
			return;
		}

		$sth = $this->pdo->prepare("SELECT feed_url FROM ttrss_feeds WHERE id = ? AND owner_uid = ?");
		$sth->execute([$feed_id, $_SESSION['uid']]);
		$row = $sth->fetch();

		if (!$row) {
			print json_encode(['error' => 'Feed nicht gefunden.']);
			return;
		}

		$html = UrlHelper::fetch(['url' => $row['feed_url'], 'timeout' => 15]);

		if (!$html) {
			print json_encode(['error' => 'Seite konnte nicht abgerufen werden.']);
			return;
		}

		$config = [
			'list_selector' => $list_selector,
			'title_selector' => $title_selector,
			'link_selector' => '',
			'content_selector' => '',
			'date_selector' => '',
		];

		$items = $this->scrape_items($html, $config, $row['feed_url']);
		print json_encode(['count' => count($items)]);
	}

	function api_version() {
		return 2;
	}
}
