<?php
class Tts extends Plugin {

	/** @var PluginHost $host */
	private $host;

	const SERVICE_BROWSER = 'browser';
	const SERVICE_SERVER = 'server';

	/** Stimmen für das Dropdown (gängige Microsoft Neural Voices) */
	const VOICES = [
		'de-DE-ConradNeural' => 'Conrad (DE, männlich)',
		'de-DE-KatjaNeural' => 'Katja (DE, weiblich)',
		'de-DE-AmalaNeural' => 'Amala (DE, weiblich)',
		'de-DE-BerndNeural' => 'Bernd (DE, männlich)',
		'de-DE-ChristophNeural' => 'Christoph (DE, männlich)',
		'de-DE-ElkeNeural' => 'Elke (DE, weiblich)',
		'de-DE-KillianNeural' => 'Killian (DE, männlich)',
		'de-DE-KlarissaNeural' => 'Klarissa (DE, weiblich)',
		'de-DE-KlausNeural' => 'Klaus (DE, männlich)',
		'de-DE-MajaNeural' => 'Maja (DE, weiblich)',
		'de-DE-RalfNeural' => 'Ralf (DE, männlich)',
		'de-DE-TanjaNeural' => 'Tanja (DE, weiblich)',
		'de-AT-IngridNeural' => 'Ingrid (AT, weiblich)',
		'de-AT-JonasNeural' => 'Jonas (AT, männlich)',
		'de-CH-JanNeural' => 'Jan (CH, männlich)',
		'de-CH-LeniNeural' => 'Leni (CH, weiblich)',
		'en-US-JennyNeural' => 'Jenny (US, female)',
		'en-US-GuyNeural' => 'Guy (US, male)',
		'en-GB-SoniaNeural' => 'Sonia (GB, female)',
		'en-GB-RyanNeural' => 'Ryan (GB, male)',
	];

	function about() {
		return [1.1,
			"Text-to-Speech: Artikel vorlesen per Browser oder Edge-TTS-Server",
			"tt-ss",
			false];
	}

	/**
	 * CSRF-Prüfung für stream_audio überspringen, da <audio src="...">
	 * einen GET-Request macht. Die Methode ist über $_SESSION['uid'] geschützt.
	 */
	function csrf_ignore($method): bool {
		return $method === 'stream_audio';
	}

	function init($host) {
		$this->host = $host;

		$host->add_hook($host::HOOK_ARTICLE_BUTTON, $this);
		$host->add_hook($host::HOOK_PREFS_TAB, $this);
	}

	function get_js() {
		return file_get_contents(__DIR__ . "/tts.js");
	}

	function get_css() {
		return file_get_contents(__DIR__ . "/tts.css");
	}

	/**
	 * Vorlesen-Button am Artikel.
	 */
	function hook_article_button($line) {
		$id = (int) $line['id'];
		$service = $this->host->get($this, 'service', self::SERVICE_BROWSER);

		return "<i class='material-icons tts-btn'
			data-article-id='" . $id . "'
			data-tts-service='" . htmlspecialchars($service) . "'
			onclick=\"Plugins.Tts.speak(" . $id . ")\"
			style='cursor: pointer'
			title=\"" . __('Vorlesen') . "\">volume_up</i>";
	}

	/**
	 * Einstellungsseite.
	 */
	function hook_prefs_tab($args) {
		if ($args != "prefPrefs") return;

		$service = $this->host->get($this, 'service', self::SERVICE_BROWSER);
		$endpoint = $this->host->get($this, 'endpoint', 'http://edge-tts:5050/v1/audio/speech');
		$api_key = $this->host->get($this, 'api_key', '');
		$voice = $this->host->get($this, 'voice', 'de-DE-ConradNeural');
		$model = $this->host->get($this, 'model', 'tts-1');
		$speed = $this->host->get($this, 'speed', '1.0');

		?>
		<div dojoType="dijit.layout.AccordionPane"
			title="<i class='material-icons'>volume_up</i> <?= __('Text-to-Speech') ?>">

			<form dojoType='dijit.form.Form'>
				<?= \Controls\pluginhandler_tags($this, "save") ?>

				<script type="dojo/method" event="onSubmit" args="evt">
					evt.preventDefault();
					xhr.post("backend.php", this.getValues(), function (reply) {
						Notify.info(reply);
					});
				</script>

				<fieldset>
					<legend><?= __('TTS-Service') ?></legend>

					<label class="checkbox">
						<input dojoType="dijit.form.RadioButton" type="radio"
							name="service" value="<?= self::SERVICE_BROWSER ?>"
							<?= $service === self::SERVICE_BROWSER ? 'checked' : '' ?>>
						<?= __('Browser (Web Speech API) — keine Konfiguration nötig') ?>
					</label><br>

					<label class="checkbox">
						<input dojoType="dijit.form.RadioButton" type="radio"
							name="service" value="<?= self::SERVICE_SERVER ?>"
							<?= $service === self::SERVICE_SERVER ? 'checked' : '' ?>>
						<?= __('Server (OpenAI-kompatibler Endpunkt, z.B. edge-tts)') ?>
					</label>
				</fieldset>

				<fieldset>
					<legend><?= __('Server-Konfiguration') ?></legend>

					<label><?= __('API-Endpunkt:') ?></label>
					<input dojoType="dijit.form.TextBox" name="endpoint"
						value="<?= htmlspecialchars($endpoint) ?>"
						style="width: 100%; font-family: monospace"
						placeholder="http://edge-tts:5050/v1/audio/speech">

					<div class="text-muted small" style="margin: 4px 0 12px">
						<?= __('OpenAI-kompatibel: POST mit {model, input, voice}. Funktioniert mit openai-edge-tts, OpenAI, Azure, etc.') ?>
					</div>

					<label><?= __('API-Key (optional):') ?></label>
					<input dojoType="dijit.form.TextBox" name="api_key" type="password"
						value="<?= htmlspecialchars($api_key) ?>"
						style="width: 400px"
						placeholder="<?= __('Leer lassen für edge-tts ohne Auth') ?>">
					<br><br>

					<label><?= __('Modell:') ?></label>
					<input dojoType="dijit.form.TextBox" name="model"
						value="<?= htmlspecialchars($model) ?>"
						style="width: 200px"
						placeholder="tts-1">
					<br><br>

					<label><?= __('Stimme:') ?></label>
					<select dojoType="dijit.form.Select" name="voice">
						<?php foreach (self::VOICES as $vid => $vlabel): ?>
						<option value="<?= htmlspecialchars($vid) ?>"
							<?= $vid === $voice ? 'selected' : '' ?>>
							<?= htmlspecialchars($vlabel) ?></option>
						<?php endforeach; ?>
					</select>

					<div class="text-muted small" style="margin: 4px 0 12px">
						<?= __('Für edge-tts: Microsoft Neural Voices. Für OpenAI: alloy, echo, fable, onyx, nova, shimmer.') ?>
					</div>

					<label><?= __('Geschwindigkeit:') ?></label>
					<input dojoType="dijit.form.NumberSpinner" name="speed"
						value="<?= htmlspecialchars($speed) ?>"
						constraints="{min: 0.5, max: 2.0, places: 1}"
						style="width: 100px">
				</fieldset>

				<button dojoType="dijit.form.Button" type="submit">
					<?= __('Speichern') ?>
				</button>
			</form>

			<fieldset style="margin-top: 16px">
				<legend><?= __('Docker-Setup für edge-tts') ?></legend>
				<pre style="background: #1a1a1a; color: #ccc; padding: 10px; border-radius: 4px; font-size: 12px; overflow-x: auto"><?= htmlspecialchars(
'# .env — API-Key generieren:
# openssl rand -hex 32
EDGE_TTS_API_KEY=dein_key_hier

# docker-compose.yml:
edge-tts:
  image: travisvn/openai-edge-tts
  environment:
    - REQUIRE_API_KEY=true
    - API_KEY=${EDGE_TTS_API_KEY}
    - DEFAULT_VOICE=de-DE-ConradNeural
    - DEFAULT_LANGUAGE=de-DE
  ports:
    - "5050:5050"
  restart: unless-stopped

# Danach hier im Plugin:
# Endpunkt: http://edge-tts:5050/v1/audio/speech
# API-Key:  denselben Key wie in EDGE_TTS_API_KEY') ?></pre>
			</fieldset>
		</div>
		<?php
	}

	/**
	 * Einstellungen speichern.
	 */
	function save(): void {
		$this->host->set($this, 'service', clean($_POST['service'] ?? self::SERVICE_BROWSER));
		$this->host->set($this, 'endpoint', clean($_POST['endpoint'] ?? ''));
		$this->host->set($this, 'api_key', clean($_POST['api_key'] ?? ''));
		$this->host->set($this, 'voice', clean($_POST['voice'] ?? 'de-DE-ConradNeural'));
		$this->host->set($this, 'model', clean($_POST['model'] ?? 'tts-1'));
		$this->host->set($this, 'speed', clean($_POST['speed'] ?? '1.0'));

		echo __("TTS-Konfiguration gespeichert.");
	}

	/**
	 * Audio streamen: Ruft den TTS-Server auf und gibt MP3 zurück.
	 * Wird als Audio-Quelle via <audio src="..."> genutzt.
	 */
	function stream_audio(): void {
		$id = (int)clean($_REQUEST['article_id'] ?? 0);

		if (!$id) {
			header('HTTP/1.1 400 Bad Request');
			echo 'Fehlende Artikel-ID';
			return;
		}

		// Artikeltext laden
		$sth = $this->pdo->prepare("SELECT content FROM ttrss_entries
			JOIN ttrss_user_entries ON ttrss_entries.id = ttrss_user_entries.ref_id
			WHERE ttrss_user_entries.ref_id = ? AND ttrss_user_entries.owner_uid = ?");
		$sth->execute([$id, $_SESSION['uid']]);
		$row = $sth->fetch();

		if (!$row) {
			header('HTTP/1.1 404 Not Found');
			echo 'Artikel nicht gefunden';
			return;
		}

		$text = strip_tags($row['content']);
		$text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
		$text = preg_replace('/\s+/', ' ', $text);
		$text = trim($text);

		if (empty($text)) {
			header('HTTP/1.1 400 Bad Request');
			echo 'Kein Text im Artikel';
			return;
		}

		// Auf 4000 Zeichen begrenzen (API-Limits)
		if (mb_strlen($text) > 4000) {
			$text = mb_substr($text, 0, 4000);
		}

		$endpoint = $this->host->get($this, 'endpoint', 'http://edge-tts:5050/v1/audio/speech');
		$api_key = $this->host->get($this, 'api_key', '');
		$voice = $this->host->get($this, 'voice', 'de-DE-ConradNeural');
		$model = $this->host->get($this, 'model', 'tts-1');
		$speed = (float)$this->host->get($this, 'speed', '1.0');

		$payload = json_encode([
			'model' => $model,
			'input' => $text,
			'voice' => $voice,
			'speed' => $speed,
			'response_format' => 'mp3',
		]);

		$headers = ['Content-Type: application/json'];
		if (!empty($api_key)) {
			$headers[] = 'Authorization: Bearer ' . $api_key;
		}

		$ch = curl_init($endpoint);
		curl_setopt_array($ch, [
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $payload,
			CURLOPT_HTTPHEADER => $headers,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => 120,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
		]);

		$audio = curl_exec($ch);
		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$error = curl_error($ch);
		curl_close($ch);

		if ($audio === false || $http_code !== 200) {
			header('HTTP/1.1 502 Bad Gateway');
			header('Content-Type: application/json');
			echo json_encode([
				'error' => 'TTS-Server-Fehler',
				'http_code' => $http_code,
				'details' => $error ?: substr($audio, 0, 500),
			]);
			return;
		}

		header('Content-Type: audio/mpeg');
		header('Content-Length: ' . strlen($audio));
		header('Cache-Control: private, max-age=3600');
		echo $audio;
	}

	/**
	 * TTS-Konfiguration als JSON für das Frontend liefern.
	 */
	function get_config(): void {
		print json_encode([
			'service' => $this->host->get($this, 'service', self::SERVICE_BROWSER),
			'speed' => (float)$this->host->get($this, 'speed', '1.0'),
		]);
	}

	function api_version() {
		return 2;
	}
}
