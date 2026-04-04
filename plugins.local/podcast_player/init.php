<?php
class Podcast_Player extends Plugin {

	/** @var PluginHost $host */
	private $host;

	function about() {
		return [1.0,
			"Erweiterter Audio-Player für Podcast-Enclosures mit Geschwindigkeitssteuerung und Fortschrittsspeicherung",
			"admin",
			false];
	}

	function init($host) {
		$this->host = $host;

		$host->add_hook($host::HOOK_FORMAT_ENCLOSURES, $this);
		$host->add_hook($host::HOOK_PREFS_TAB, $this);

		$m = new Db_Migrations();
		$m->initialize_for_plugin($this);
		$m->migrate();
	}

	function get_js() {
		return file_get_contents(__DIR__ . "/podcast_player.js");
	}

	function get_css() {
		return file_get_contents(__DIR__ . "/podcast_player.css");
	}

	function hook_format_enclosures($enclosures_formatted, $enclosures, $article_id, $always_display_enclosures, $article_content, $hide_images) {
		$audio_enclosures = [];

		foreach ($enclosures as $enc) {
			$content_type = $enc['content_type'] ?? '';
			$enc_url = $enc['content_url'] ?? '';

			if (!empty($enc_url) && (
				str_starts_with($content_type, 'audio/') ||
				preg_match('/\.(mp3|m4a|ogg|opus|wav|aac)(\?|$)/i', $enc_url)
			)) {
				$audio_enclosures[] = $enc;
			}
		}

		if (empty($audio_enclosures)) {
			return $enclosures_formatted;
		}

		$html = '';

		foreach ($audio_enclosures as $enc) {
			$url = htmlspecialchars($enc['content_url']);
			$title = htmlspecialchars($enc['title'] ?? basename(parse_url($enc['content_url'], PHP_URL_PATH)));
			$duration = '';

			if (!empty($enc['duration'])) {
				$secs = (int)$enc['duration'];
				$h = floor($secs / 3600);
				$m = floor(($secs % 3600) / 60);
				$s = $secs % 60;
				$duration = $h > 0
					? sprintf('%d:%02d:%02d', $h, $m, $s)
					: sprintf('%d:%02d', $m, $s);
			}

			$player_id = 'podcast_' . $article_id . '_' . md5($enc['content_url']);

			$html .= <<<HTML
			<div class="podcast-player">
				<div class="podcast-title">{$title}</div>
				<audio id="{$player_id}" class="podcast-audio" controls preload="none"
					data-enclosure-url="{$url}">
					<source src="{$url}" type="{$enc['content_type']}">
					Ihr Browser unterstützt das Audio-Element nicht.
				</audio>
				<div class="controls">
					<button onclick="Plugins.PodcastPlayer.skip(document.getElementById('{$player_id}'), -15)"
						title="15 Sekunden zurück">
						<i class="material-icons">replay</i> -15s
					</button>
					<button onclick="Plugins.PodcastPlayer.skip(document.getElementById('{$player_id}'), 15)"
						title="15 Sekunden vor">
						<i class="material-icons">forward</i> +15s
					</button>
					<span style="margin-left: 8px;">Tempo:</span>
					<button class="speed-btn" data-speed="0.5"
						onclick="Plugins.PodcastPlayer.setSpeed(document.getElementById('{$player_id}'), 0.5)">0.5x</button>
					<button class="speed-btn active" data-speed="1"
						onclick="Plugins.PodcastPlayer.setSpeed(document.getElementById('{$player_id}'), 1)">1x</button>
					<button class="speed-btn" data-speed="1.5"
						onclick="Plugins.PodcastPlayer.setSpeed(document.getElementById('{$player_id}'), 1.5)">1.5x</button>
					<button class="speed-btn" data-speed="2"
						onclick="Plugins.PodcastPlayer.setSpeed(document.getElementById('{$player_id}'), 2)">2x</button>
				</div>
HTML;

			if ($duration) {
				$html .= "<div class=\"status\">Dauer: {$duration}</div>";
			}

			$html .= '</div>';
		}

		// Nicht-Audio-Enclosures beibehalten
		$non_audio_enclosures = [];
		foreach ($enclosures as $enc) {
			$content_type = $enc['content_type'] ?? '';
			$enc_url = $enc['content_url'] ?? '';

			if (empty($enc_url) || (!str_starts_with($content_type, 'audio/') &&
				!preg_match('/\.(mp3|m4a|ogg|opus|wav|aac)(\?|$)/i', $enc_url))) {
				$non_audio_enclosures[] = $enc;
			}
		}

		if (!empty($non_audio_enclosures)) {
			return ['enclosures_formatted' => $html . $enclosures_formatted, 'enclosures' => $non_audio_enclosures];
		}

		return $html;
	}

	function save_progress() : void {
		$url = clean($_REQUEST['url'] ?? '');
		$position = (float)($_REQUEST['position'] ?? 0);
		$completed = !empty($_REQUEST['completed']);

		if (empty($url)) {
			print json_encode(['error' => 'URL fehlt.']);
			return;
		}

		$sth = $this->pdo->prepare("INSERT INTO ttrss_plugin_podcast_progress
			(owner_uid, enclosure_url, position_seconds, completed, last_played)
			VALUES (?, ?, ?, ?, NOW())
			ON CONFLICT (owner_uid, enclosure_url)
			DO UPDATE SET position_seconds = ?, completed = ?, last_played = NOW()");

		$sth->execute([
			$_SESSION['uid'], $url, $position, $completed,
			$position, $completed
		]);

		print json_encode(['ok' => true]);
	}

	function get_progress() : void {
		$url = clean($_REQUEST['url'] ?? '');

		if (empty($url)) {
			print json_encode(['position' => 0, 'completed' => false]);
			return;
		}

		$sth = $this->pdo->prepare("SELECT position_seconds, completed
			FROM ttrss_plugin_podcast_progress
			WHERE owner_uid = ? AND enclosure_url = ?");
		$sth->execute([$_SESSION['uid'], $url]);

		if ($row = $sth->fetch()) {
			print json_encode([
				'position' => (float)$row['position_seconds'],
				'completed' => (bool)$row['completed']
			]);
		} else {
			print json_encode(['position' => 0, 'completed' => false]);
		}
	}

	function hook_prefs_tab($args) {
		if ($args != "prefPrefs") return;

		?>
		<div dojoType="dijit.layout.AccordionPane"
			title="<i class='material-icons'>headphones</i> <?= __('Podcast Player') ?>">

			<h3><?= __('Erweiterter Podcast-Player') ?></h3>

			<p><?= __('Dieses Plugin ersetzt den Standard-Enclosure-Player für Audio-Dateien durch einen erweiterten Player mit folgenden Funktionen:') ?></p>

			<ul>
				<li><?= __('Geschwindigkeitssteuerung (0.5x, 1x, 1.5x, 2x)') ?></li>
				<li><?= __('Vor-/Zurückspringen um 15 Sekunden') ?></li>
				<li><?= __('Automatische Fortschrittsspeicherung') ?></li>
				<li><?= __('Wiedergabeposition wird beim nächsten Öffnen wiederhergestellt') ?></li>
			</ul>
		</div>
		<?php
	}

	function api_version() {
		return 2;
	}
}
