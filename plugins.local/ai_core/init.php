<?php
/**
 * AI Core -- LLM-Abstraktionsschicht für TT-RSS Plugins.
 *
 * Unterstützte Provider:
 * - OpenAI (GPT-4o, GPT-4, GPT-3.5)
 * - Anthropic (Claude)
 * - Ollama (lokal, selbst gehostet)
 * - Benutzerdefinierter OpenAI-kompatibler Endpoint
 */
class Ai_Core extends Plugin {

	/** @var PluginHost $host */
	private $host;

	const PROVIDER_OPENAI = 'openai';
	const PROVIDER_ANTHROPIC = 'anthropic';
	const PROVIDER_OLLAMA = 'ollama';
	const PROVIDER_CUSTOM = 'custom';

	function about() {
		return [1.0,
			"LLM-Abstraktionsschicht: Zentrale KI-Konfiguration für alle AI-Plugins",
			"tt-ss",
			false];
	}

	function init($host) {
		$this->host = $host;

		$host->add_hook($host::HOOK_PREFS_TAB, $this);
	}

	/**
	 * Gibt die aktuelle Provider-Konfiguration zurück.
	 * @return array{provider: string, api_key: string, endpoint: string, model: string}
	 */
	static function get_config(): array {
		$host = PluginHost::getInstance();
		$plugin = $host->get_plugin('ai_core');

		if (!$plugin) {
			return [
				'provider' => self::PROVIDER_OLLAMA,
				'api_key' => '',
				'endpoint' => 'http://localhost:11434',
				'model' => 'llama3.2'
			];
		}

		return [
			'provider' => $host->get($plugin, 'provider', self::PROVIDER_OLLAMA),
			'api_key' => $host->get($plugin, 'api_key', ''),
			'endpoint' => $host->get($plugin, 'endpoint', 'http://localhost:11434'),
			'model' => $host->get($plugin, 'model', 'llama3.2'),
		];
	}

	/**
	 * Sendet eine Anfrage an den konfigurierten LLM-Provider.
	 * @param string $system_prompt System-Prompt
	 * @param string $user_message Benutzer-Nachricht
	 * @param int $max_tokens Maximale Antwortlänge
	 * @return string|false Antworttext oder false bei Fehler
	 */
	static function complete(string $system_prompt, string $user_message, int $max_tokens = 1024) {
		$config = self::get_config();

		switch ($config['provider']) {
			case self::PROVIDER_OPENAI:
			case self::PROVIDER_CUSTOM:
				return self::complete_openai($config, $system_prompt, $user_message, $max_tokens);
			case self::PROVIDER_ANTHROPIC:
				return self::complete_anthropic($config, $system_prompt, $user_message, $max_tokens);
			case self::PROVIDER_OLLAMA:
				return self::complete_ollama($config, $system_prompt, $user_message, $max_tokens);
			default:
				Debug::log("ai_core: Unbekannter Provider: " . $config['provider'], Debug::LOG_VERBOSE);
				return false;
		}
	}

	/**
	 * OpenAI-kompatible API (funktioniert auch mit Custom-Endpoints).
	 */
	private static function complete_openai(array $config, string $system_prompt, string $user_message, int $max_tokens) {
		if ($config['provider'] === self::PROVIDER_CUSTOM) {
			$parsed = parse_url($config['endpoint']);
			$scheme = $parsed['scheme'] ?? '';
			if (!in_array($scheme, ['http', 'https'])) {
				Debug::log("ai_core: Ungültiges Schema im Custom-Endpoint: $scheme", Debug::LOG_VERBOSE);
				return false;
			}
			$endpoint = rtrim($config['endpoint'], '/') . '/v1/chat/completions';
		} else {
			$endpoint = 'https://api.openai.com/v1/chat/completions';
		}

		$payload = json_encode([
			'model' => $config['model'],
			'messages' => [
				['role' => 'system', 'content' => $system_prompt],
				['role' => 'user', 'content' => $user_message]
			],
			'max_tokens' => $max_tokens,
			'temperature' => 0.3
		]);

		$response = UrlHelper::fetch([
			'url' => $endpoint,
			'timeout' => 60,
			'post_query' => $payload,
			'type' => 'application/json',
			'followlocation' => false,
			'extra_headers' => [
				'Authorization: Bearer ' . $config['api_key'],
				'Content-Type: application/json'
			]
		]);

		if (!$response) return false;

		$data = json_decode($response, true);
		return $data['choices'][0]['message']['content'] ?? false;
	}

	/**
	 * Anthropic Claude API.
	 */
	private static function complete_anthropic(array $config, string $system_prompt, string $user_message, int $max_tokens) {
		$payload = json_encode([
			'model' => $config['model'],
			'system' => $system_prompt,
			'messages' => [
				['role' => 'user', 'content' => $user_message]
			],
			'max_tokens' => $max_tokens,
			'temperature' => 0.3
		]);

		$response = UrlHelper::fetch([
			'url' => 'https://api.anthropic.com/v1/messages',
			'timeout' => 60,
			'post_query' => $payload,
			'type' => 'application/json',
			'followlocation' => false,
			'extra_headers' => [
				'x-api-key: ' . $config['api_key'],
				'anthropic-version: 2023-06-01',
				'Content-Type: application/json'
			]
		]);

		if (!$response) return false;

		$data = json_decode($response, true);
		return $data['content'][0]['text'] ?? false;
	}

	/**
	 * Ollama (lokal).
	 */
	private static function complete_ollama(array $config, string $system_prompt, string $user_message, int $max_tokens) {
		$parsed = parse_url($config['endpoint']);
		$host = $parsed['host'] ?? '';
		// Grundlegende SSRF-Schutzmaßnahme: Nur erwartete Hosts/Schemata erlauben
		$scheme = $parsed['scheme'] ?? '';
		if (!in_array($scheme, ['http', 'https'])) {
			Debug::log("ai_core: Ungültiges Schema im Endpoint: $scheme", Debug::LOG_VERBOSE);
			return false;
		}
		$endpoint = rtrim($config['endpoint'], '/') . '/api/chat';

		$payload = json_encode([
			'model' => $config['model'],
			'messages' => [
				['role' => 'system', 'content' => $system_prompt],
				['role' => 'user', 'content' => $user_message]
			],
			'stream' => false,
			'options' => [
				'num_predict' => $max_tokens
			]
		]);

		$response = UrlHelper::fetch([
			'url' => $endpoint,
			'timeout' => 120,
			'post_query' => $payload,
			'type' => 'application/json',
			'followlocation' => false
		]);

		if (!$response) return false;

		$data = json_decode($response, true);
		return $data['message']['content'] ?? false;
	}

	function hook_prefs_tab($args) {
		if ($args != "prefPrefs") return;

		$provider = $this->host->get($this, 'provider', self::PROVIDER_OLLAMA);
		$api_key = $this->host->get($this, 'api_key', '');
		$endpoint = $this->host->get($this, 'endpoint', 'http://localhost:11434');
		$model = $this->host->get($this, 'model', 'llama3.2');

		?>
		<div dojoType="dijit.layout.AccordionPane"
			title="<i class='material-icons'>smart_toy</i> <?= __('KI-Konfiguration (AI Core)') ?>">

			<form dojoType="dijit.form.Form">

				<?= \Controls\pluginhandler_tags($this, "save") ?>

				<script type="dojo/method" event="onSubmit" args="evt">
					evt.preventDefault();
					if (this.validate()) {
						Notify.progress('Saving data...', true);
						xhr.post("backend.php", this.getValues(), (reply) => {
							Notify.info(reply);
						})
					}
				</script>

				<?= format_notice(__("Zentrale KI-Konfiguration für alle AI-Plugins (Zusammenfassungen, Übersetzung, Tags, etc.). Für lokalen Betrieb empfiehlt sich Ollama.")) ?>

				<fieldset>
					<label><?= __("Provider:") ?></label>
					<select name="provider" dojoType="dijit.form.Select">
						<option value="<?= self::PROVIDER_OLLAMA ?>" <?= $provider == self::PROVIDER_OLLAMA ? 'selected' : '' ?>><?= __('Ollama (lokal)') ?></option>
						<option value="<?= self::PROVIDER_OPENAI ?>" <?= $provider == self::PROVIDER_OPENAI ? 'selected' : '' ?>><?= __('OpenAI') ?></option>
						<option value="<?= self::PROVIDER_ANTHROPIC ?>" <?= $provider == self::PROVIDER_ANTHROPIC ? 'selected' : '' ?>><?= __('Anthropic Claude') ?></option>
						<option value="<?= self::PROVIDER_CUSTOM ?>" <?= $provider == self::PROVIDER_CUSTOM ? 'selected' : '' ?>><?= __('Benutzerdefiniert (OpenAI-kompatibel)') ?></option>
					</select>
				</fieldset>

				<fieldset>
					<label><?= __("API-Key:") ?></label>
					<input dojoType="dijit.form.TextBox"
						type="password"
						name="api_key"
						style="width: 400px"
						placeholder="<?= __('Nicht nötig für Ollama') ?>"
						value="<?= htmlspecialchars($api_key) ?>">
				</fieldset>

				<fieldset>
					<label><?= __("Endpoint-URL:") ?></label>
					<input dojoType="dijit.form.TextBox"
						name="endpoint"
						style="width: 400px"
						placeholder="http://localhost:11434"
						value="<?= htmlspecialchars($endpoint) ?>">
				</fieldset>

				<fieldset>
					<label><?= __("Modell:") ?></label>
					<input dojoType="dijit.form.TextBox"
						name="model"
						style="width: 300px"
						placeholder="llama3.2"
						value="<?= htmlspecialchars($model) ?>">
				</fieldset>

				<hr/>

				<?= \Controls\submit_tag(__("Speichern")) ?>
			</form>

			<hr/>

			<h3><?= __('Verbindung testen') ?></h3>
			<form dojoType="dijit.form.Form">
				<?= \Controls\pluginhandler_tags($this, "test") ?>
				<script type="dojo/method" event="onSubmit" args="evt">
					evt.preventDefault();
					Notify.progress('Teste Verbindung...');
					xhr.post("backend.php", this.getValues(), (reply) => {
						Notify.info(reply);
					})
				</script>
				<button dojoType="dijit.form.Button" type="submit">
					<i class="material-icons">science</i> <?= __('Verbindung testen') ?>
				</button>
			</form>
		</div>
		<?php
	}

	function save(): void {
		$endpoint = clean($_POST['endpoint'] ?? 'http://localhost:11434');

		// Endpoint-URL validieren: nur http(s) erlauben
		$parsed = parse_url($endpoint);
		$scheme = $parsed['scheme'] ?? '';
		if (!empty($endpoint) && !in_array($scheme, ['http', 'https'])) {
			echo __("Ungültiges URL-Schema. Nur http und https sind erlaubt.");
			return;
		}

		$this->host->set($this, 'provider', clean($_POST['provider'] ?? self::PROVIDER_OLLAMA));
		$this->host->set($this, 'api_key', clean($_POST['api_key'] ?? ''));
		$this->host->set($this, 'endpoint', $endpoint);
		$this->host->set($this, 'model', clean($_POST['model'] ?? 'llama3.2'));

		echo __("KI-Konfiguration gespeichert.");
	}

	function test(): void {
		$result = self::complete(
			"Du bist ein Test-Assistent.",
			"Antworte mit genau einem Satz: Die Verbindung funktioniert.",
			50
		);

		if ($result !== false) {
			echo sprintf(__("Verbindung erfolgreich. Antwort: %s"), htmlspecialchars($result));
		} else {
			echo __("Verbindung fehlgeschlagen. Bitte Konfiguration prüfen.");
		}
	}

	function api_version() {
		return 2;
	}
}
