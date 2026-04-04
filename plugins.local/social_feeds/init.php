<?php
class Social_Feeds extends Plugin {

	/** @var PluginHost $host */
	private $host;

	function about() {
		return [1.0,
			"Social-Media-URLs automatisch in RSS-Feed-URLs umwandeln beim Abonnieren",
			"admin",
			false];
	}

	function init($host) {
		$this->host = $host;

		$host->add_hook($host::HOOK_SUBSCRIBE_FEED, $this);
		$host->add_hook($host::HOOK_PREFS_TAB, $this);
	}

	function hook_subscribe_feed($contents, $url, $auth_login, $auth_pass) {
		$rewritten_url = $this->rewrite_url($url);

		if ($rewritten_url && $rewritten_url !== $url) {
			Debug::log("social_feeds: URL umgeschrieben von {$url} zu {$rewritten_url}");

			$new_contents = UrlHelper::fetch(['url' => $rewritten_url, 'timeout' => 15]);

			if ($new_contents) {
				return $new_contents;
			}
		}

		return $contents;
	}

	/**
	 * URL-Umschreibungsregeln für bekannte Plattformen.
	 */
	private function rewrite_url(string $url): ?string {
		$parsed = parse_url($url);
		$host = strtolower($parsed['host'] ?? '');
		$path = $parsed['path'] ?? '';

		// Reddit: /r/subreddit
		if (preg_match('#(^|\.)reddit\.com$#', $host)) {
			if (preg_match('#^/r/([\w]+)/?$#', $path, $m)) {
				return "https://www.reddit.com/r/{$m[1]}/.rss";
			}
			if (preg_match('#^/user/([\w]+)/?$#', $path, $m)) {
				return "https://www.reddit.com/user/{$m[1]}/.rss";
			}
		}

		// YouTube: /channel/ID oder /@handle
		if (preg_match('#(^|\.)youtube\.com$#', $host)) {
			if (preg_match('#^/channel/([\w-]+)#', $path, $m)) {
				return "https://www.youtube.com/feeds/videos.xml?channel_id={$m[1]}";
			}
			if (preg_match('#^/@([\w.-]+)#', $path, $m)) {
				return $this->resolve_youtube_handle($url);
			}
		}

		// GitHub: /user/repo oder /user/repo/commits
		if ($host === 'github.com') {
			if (preg_match('#^/([\w.-]+)/([\w.-]+)/commits#', $path, $m)) {
				return "https://github.com/{$m[1]}/{$m[2]}/commits.atom";
			}
			if (preg_match('#^/([\w.-]+)/([\w.-]+)/?$#', $path, $m)) {
				return "https://github.com/{$m[1]}/{$m[2]}/releases.atom";
			}
		}

		// Mastodon: /@user auf beliebiger Instanz
		if (preg_match('#^/@[\w]+/?$#', $path)) {
			return $this->resolve_mastodon_feed($url);
		}

		return null;
	}

	/**
	 * YouTube-Handle zu Channel-ID auflösen.
	 */
	private function resolve_youtube_handle(string $url): ?string {
		$html = UrlHelper::fetch(['url' => $url, 'timeout' => 15]);

		if (!$html) {
			return null;
		}

		// Channel-ID aus Meta-Tag oder Link extrahieren
		if (preg_match('#"channelId"\s*:\s*"(UC[\w-]+)"#', $html, $m)) {
			return "https://www.youtube.com/feeds/videos.xml?channel_id={$m[1]}";
		}

		if (preg_match('#<meta\s+itemprop="channelId"\s+content="(UC[\w-]+)"#', $html, $m)) {
			return "https://www.youtube.com/feeds/videos.xml?channel_id={$m[1]}";
		}

		return null;
	}

	/**
	 * Mastodon-Profil nach Atom-Feed-Link durchsuchen.
	 */
	private function resolve_mastodon_feed(string $url): ?string {
		$html = UrlHelper::fetch(['url' => $url, 'timeout' => 15]);

		if (!$html) {
			return null;
		}

		if (preg_match('#<link[^>]+rel=["\']alternate["\'][^>]+type=["\']application/atom\+xml["\'][^>]+href=["\']([^"\']+)["\']#i', $html, $m)) {
			return $m[1];
		}

		// Auch umgekehrte Reihenfolge der Attribute prüfen
		if (preg_match('#<link[^>]+href=["\']([^"\']+)["\'][^>]+type=["\']application/atom\+xml["\']#i', $html, $m)) {
			return $m[1];
		}

		return null;
	}

	function hook_prefs_tab($args) {
		if ($args != "prefFeeds") return;

		?>
		<div dojoType="dijit.layout.AccordionPane"
			title="<i class='material-icons'>people</i> <?= __('Social Feeds') ?>">

			<h3><?= __('Automatische Feed-Erkennung für soziale Medien') ?></h3>

			<p><?= __('Dieses Plugin wandelt beim Abonnieren automatisch URLs folgender Plattformen in RSS-Feed-URLs um:') ?></p>

			<ul>
				<li><b>Reddit</b> &mdash; <code>reddit.com/r/subreddit</code> &rarr; RSS-Feed</li>
				<li><b>YouTube</b> &mdash; <code>youtube.com/channel/ID</code> oder <code>youtube.com/@handle</code> &rarr; Atom-Feed</li>
				<li><b>GitHub</b> &mdash; <code>github.com/user/repo</code> &rarr; Releases-Atom-Feed</li>
				<li><b>GitHub Commits</b> &mdash; <code>github.com/user/repo/commits</code> &rarr; Commits-Atom-Feed</li>
				<li><b>Mastodon</b> &mdash; <code>instanz.example/@user</code> &rarr; Atom-Feed (automatische Erkennung)</li>
			</ul>

			<p class="text-muted"><?= __('Einfach die normale URL der Seite als Feed-URL eingeben. Das Plugin erkennt die Plattform und verwendet automatisch die passende Feed-URL.') ?></p>
		</div>
		<?php
	}

	function api_version() {
		return 2;
	}
}
