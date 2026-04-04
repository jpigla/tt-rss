<?php
class Translate extends Plugin {

	/** @var PluginHost $host */
	private $host;

	const SERVICE_LIBRETRANSLATE = 'libretranslate';
	const SERVICE_DEEPL = 'deepl';
	const SERVICE_AI = 'ai';

	const LANGUAGES = [
		'de' => 'Deutsch',
		'en' => 'Englisch',
		'fr' => 'Französisch',
		'es' => 'Spanisch',
		'it' => 'Italienisch',
		'pt' => 'Portugiesisch',
		'ja' => 'Japanisch',
		'zh' => 'Chinesisch',
		'ru' => 'Russisch',
		'ar' => 'Arabisch',
	];

	function about() {
		return [1.0,
			"Artikel übersetzen mit LibreTranslate, DeepL oder KI",
			"tt-ss",
			false];
	}

	function init($host) {
		$this->host = $host;

		$host->add_hook($host::HOOK_ARTICLE_BUTTON, $this);
		$host->add_hook($host::HOOK_PREFS_TAB, $this);

		$this->init_schema();
	}

	private function init_schema(): void {
		try {
			$this->pdo->query("SELECT 1 FROM ttrss_plugin_translations LIMIT 1");
		} catch (\PDOException $e) {
			$schema_file = __DIR__ . "/sql/pgsql/schema.sql";
			if (file_exists($schema_file)) {
				$sql = file_get_contents($schema_file);
				$this->pdo->exec($sql);
			}
		}
	}

	function get_js() {
		return file_get_contents(__DIR__ . "/translate.js");
	}

	function get_css() {
		return file_get_contents(__DIR__ . "/translate.css");
	}

	function hook_article_button($line) {
		$id = $line['id'];

		return "<i class='material-icons translate-btn'
			data-article-id='$id'
			onclick=\"Plugins.Translate.showMenu($id)\"
			style='cursor: pointer'
			title=\"" . __('Übersetzen') . "\">translate</i>";
	}

	function translate(): void {
		$article_id = (int) clean($_REQUEST['article_id'] ?? 0);
		$target_lang = clean($_REQUEST['target_lang'] ?? 'de');
		$owner_uid = $_SESSION['uid'];

		if ($article_id <= 0) {
			print json_encode(["error" => "Ungültige Artikel-ID"]);
			return;
		}

		// Cache prüfen
		$sth = $this->pdo->prepare("SELECT translated_title, translated_content
			FROM ttrss_plugin_translations
			WHERE ref_id = ? AND target_lang = ?
			ORDER BY created_at DESC LIMIT 1");
		$sth->execute([$article_id, $target_lang]);

		if ($row = $sth->fetch()) {
			print json_encode([
				"title" => $row['translated_title'],
				"content" => $row['translated_content'],
				"cached" => true
			]);
			return;
		}

		$sth = $this->pdo->prepare("SELECT e.title, e.content
			FROM ttrss_entries e
			JOIN ttrss_user_entries ue ON ue.ref_id = e.id
			WHERE e.id = ? AND ue.owner_uid = ?");
		$sth->execute([$article_id, $owner_uid]);

		$article = $sth->fetch();
		if (!$article) {
			print json_encode(["error" => "Artikel nicht gefunden"]);
			return;
		}

		$service = $this->host->get($this, 'service', self::SERVICE_AI);
		$content = strip_tags($article['content']);

		if (mb_strlen($content) > 8000) {
			$content = mb_substr($content, 0, 8000) . '...';
		}

		$lang_name = self::LANGUAGES[$target_lang] ?? $target_lang;

		switch ($service) {
			case self::SERVICE_LIBRETRANSLATE:
				$translated_title = $this->translate_libretranslate($article['title'], $target_lang);
				$translated_content = $this->translate_libretranslate($content, $target_lang);
				break;

			case self::SERVICE_DEEPL:
				$translated_title = $this->translate_deepl($article['title'], $target_lang);
				$translated_content = $this->translate_deepl($content, $target_lang);
				break;

			case self::SERVICE_AI:
			default:
				$translated_title = $this->translate_ai($article['title'], $lang_name);
				$translated_content = $this->translate_ai($content, $lang_name);
				break;
		}

		if ($translated_title === false || $translated_content === false) {
			print json_encode(["error" => "Übersetzung fehlgeschlagen. Bitte Konfiguration prüfen."]);
			return;
		}

		$sth = $this->pdo->prepare("INSERT INTO ttrss_plugin_translations
			(ref_id, target_lang, translated_title, translated_content)
			VALUES (?, ?, ?, ?)");
		$sth->execute([$article_id, $target_lang, $translated_title, $translated_content]);

		print json_encode([
			"title" => $translated_title,
			"content" => $translated_content,
			"cached" => false
		]);
	}

	private function translate_libretranslate(string $text, string $target): string|false {
		$endpoint = rtrim($this->host->get($this, 'endpoint', 'http://localhost:5000'), '/') . '/translate';
		$api_key = $this->host->get($this, 'api_key', '');

		$payload = [
			'q' => $text,
			'source' => 'auto',
			'target' => $target,
			'format' => 'text',
		];

		if (!empty($api_key)) {
			$payload['api_key'] = $api_key;
		}

		$response = UrlHelper::fetch([
			'url' => $endpoint,
			'timeout' => 60,
			'post_query' => json_encode($payload),
			'type' => 'application/json',
			'followlocation' => false,
			'extra_headers' => ['Content-Type: application/json']
		]);

		if (!$response) return false;

		$data = json_decode($response, true);
		return $data['translatedText'] ?? false;
	}

	private function translate_deepl(string $text, string $target): string|false {
		$api_key = $this->host->get($this, 'api_key', '');

		if (empty($api_key)) return false;

		$deepl_lang = strtoupper($target);

		$response = UrlHelper::fetch([
			'url' => 'https://api-free.deepl.com/v2/translate',
			'timeout' => 60,
			'post_query' => json_encode([
				'text' => [$text],
				'target_lang' => $deepl_lang,
			]),
			'type' => 'application/json',
			'followlocation' => false,
			'extra_headers' => [
				'Authorization: DeepL-Auth-Key ' . $api_key,
				'Content-Type: application/json'
			]
		]);

		if (!$response) return false;

		$data = json_decode($response, true);
		return $data['translations'][0]['text'] ?? false;
	}

	private function translate_ai(string $text, string $lang_name): string|false {
		if (!PluginHost::getInstance()->get_plugin('ai_core')) {
			return false;
		}

		return Ai_Core::complete(
			"Du bist ein professioneller Übersetzer. Übersetze den folgenden Text ins $lang_name. Gib nur die Übersetzung zurück, ohne Erklärungen.",
			$text,
			2048
		);
	}

	function hook_prefs_tab($args) {
		if ($args != "prefPrefs") return;

		$service = $this->host->get($this, 'service', self::SERVICE_AI);
		$api_key = $this->host->get($this, 'api_key', '');
		$endpoint = $this->host->get($this, 'endpoint', 'http://localhost:5000');
		$default_lang = $this->host->get($this, 'default_target_lang', 'de');

		?>
		<div dojoType="dijit.layout.AccordionPane"
			title="<i class='material-icons'>translate</i> <?= __('Übersetzung') ?>">

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

				<?= format_notice(__("Übersetzungsdienst konfigurieren. Bei 'KI' wird ai_core verwendet.")) ?>

				<fieldset>
					<label><?= __("Dienst:") ?></label>
					<select name="service" dojoType="dijit.form.Select">
						<option value="<?= self::SERVICE_AI ?>" <?= $service == self::SERVICE_AI ? 'selected' : '' ?>><?= __('KI (ai_core)') ?></option>
						<option value="<?= self::SERVICE_LIBRETRANSLATE ?>" <?= $service == self::SERVICE_LIBRETRANSLATE ? 'selected' : '' ?>><?= __('LibreTranslate') ?></option>
						<option value="<?= self::SERVICE_DEEPL ?>" <?= $service == self::SERVICE_DEEPL ? 'selected' : '' ?>><?= __('DeepL') ?></option>
					</select>
				</fieldset>

				<fieldset>
					<label><?= __("API-Key:") ?></label>
					<input dojoType="dijit.form.TextBox"
						type="password"
						name="api_key"
						style="width: 400px"
						placeholder="<?= __('Für DeepL/LibreTranslate') ?>"
						value="<?= htmlspecialchars($api_key) ?>">
				</fieldset>

				<fieldset>
					<label><?= __("Endpoint (LibreTranslate):") ?></label>
					<input dojoType="dijit.form.TextBox"
						name="endpoint"
						style="width: 400px"
						placeholder="http://localhost:5000"
						value="<?= htmlspecialchars($endpoint) ?>">
				</fieldset>

				<fieldset>
					<label><?= __("Standard-Zielsprache:") ?></label>
					<select name="default_target_lang" dojoType="dijit.form.Select">
						<?php foreach (self::LANGUAGES as $code => $name) { ?>
							<option value="<?= $code ?>" <?= $default_lang == $code ? 'selected' : '' ?>><?= __($name) ?></option>
						<?php } ?>
					</select>
				</fieldset>

				<hr/>

				<?= \Controls\submit_tag(__("Speichern")) ?>
			</form>
		</div>
		<?php
	}

	function save(): void {
		$this->host->set($this, 'service', clean($_POST['service'] ?? self::SERVICE_AI));
		$this->host->set($this, 'api_key', clean($_POST['api_key'] ?? ''));
		$this->host->set($this, 'endpoint', clean($_POST['endpoint'] ?? 'http://localhost:5000'));
		$this->host->set($this, 'default_target_lang', clean($_POST['default_target_lang'] ?? 'de'));

		echo __("Übersetzungskonfiguration gespeichert.");
	}

	function api_version() {
		return 2;
	}
}
