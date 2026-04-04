<?php
class Save_To extends Plugin {

	/** @var PluginHost $host */
	private $host;

	/** @var array<string, string> Unterstützte Dienste */
	private array $services = [
		'wallabag' => 'Wallabag',
		'pocket' => 'Pocket',
		'linkding' => 'Linkding',
		'instapaper' => 'Instapaper',
	];

	function about() {
		return [1.0,
			"Artikel an externe Dienste senden (Wallabag, Pocket, Instapaper, Linkding)",
			"tt-ss",
			false];
	}

	function init($host) {
		$this->host = $host;

		$host->add_hook($host::HOOK_ARTICLE_BUTTON, $this);
		$host->add_hook($host::HOOK_PREFS_TAB, $this);
	}

	function get_js() {
		return file_get_contents(__DIR__ . "/save_to.js");
	}

	function get_css() {
		return file_get_contents(__DIR__ . "/save_to.css");
	}

	/**
	 * @param array<string, mixed> $line
	 * @return string
	 */
	function hook_article_button($line) {
		$id = (int) $line['id'];

		return "<i class='material-icons save-to-btn'
			onclick=\"Plugins.Save_To.showMenu(event, " . $id . ")\"
			style='cursor: pointer'
			title=\"" . __('Speichern unter...') . "\">bookmark_add</i>";
	}

	/**
	 * Artikel an externen Dienst senden.
	 */
	function save_article(): void {
		$article_id = (int)clean($_REQUEST['article_id'] ?? 0);
		$service = clean($_REQUEST['service'] ?? '');

		if ($article_id <= 0 || empty($service)) {
			print json_encode(["error" => __("Ungültige Parameter.")]);
			return;
		}

		// Artikel-URL aus DB holen
		$sth = $this->pdo->prepare("SELECT e.title, e.link
			FROM ttrss_entries e
			JOIN ttrss_user_entries ue ON ue.ref_id = e.id
			WHERE e.id = ? AND ue.owner_uid = ?");
		$sth->execute([$article_id, $_SESSION['uid']]);
		$article = $sth->fetch();

		if (!$article) {
			print json_encode(["error" => __("Artikel nicht gefunden.")]);
			return;
		}

		$url = $article['link'];
		$title = $article['title'];

		$config = $this->host->get($this, "service_$service", []);
		if (!is_array($config) || empty($config['enabled'])) {
			print json_encode(["error" => __("Dienst nicht konfiguriert oder deaktiviert.")]);
			return;
		}

		$result = false;

		switch ($service) {
			case 'wallabag':
				$result = $this->save_to_wallabag($url, $title, $config);
				break;
			case 'pocket':
				$result = $this->save_to_pocket($url, $config);
				break;
			case 'linkding':
				$result = $this->save_to_linkding($url, $title, $config);
				break;
			case 'instapaper':
				$result = $this->save_to_instapaper($url, $config);
				break;
		}

		if ($result) {
			print json_encode(["message" => sprintf(__("Artikel an %s gesendet."), $this->services[$service] ?? $service)]);
		} else {
			print json_encode(["error" => sprintf(__("Fehler beim Senden an %s."), $this->services[$service] ?? $service)]);
		}
	}

	private function save_to_wallabag(string $url, string $title, array $config): bool {
		$base_url = rtrim($config['url'] ?? '', '/');
		if (empty($base_url)) return false;

		// Zunächst Token holen
		$token_response = UrlHelper::fetch([
			'url' => "$base_url/oauth/v2/token",
			'timeout' => 15,
			'post_query' => json_encode([
				'grant_type' => 'password',
				'client_id' => $config['client_id'] ?? '',
				'client_secret' => $config['client_secret'] ?? '',
				'username' => $config['username'] ?? '',
				'password' => $config['password'] ?? '',
			]),
			'type' => 'application/json',
		]);

		if (!$token_response) return false;

		$token_data = json_decode($token_response, true);
		$access_token = $token_data['access_token'] ?? '';

		if (empty($access_token)) return false;

		$result = UrlHelper::fetch([
			'url' => "$base_url/api/entries.json",
			'timeout' => 15,
			'post_query' => json_encode(['url' => $url]),
			'type' => 'application/json',
			'extra_headers' => ["Authorization: Bearer $access_token"],
		]);

		return $result !== false;
	}

	private function save_to_pocket(string $url, array $config): bool {
		$consumer_key = $config['consumer_key'] ?? '';
		$access_token = $config['access_token'] ?? '';

		if (empty($consumer_key) || empty($access_token)) return false;

		$result = UrlHelper::fetch([
			'url' => 'https://getpocket.com/v3/add',
			'timeout' => 15,
			'post_query' => json_encode([
				'url' => $url,
				'consumer_key' => $consumer_key,
				'access_token' => $access_token,
			]),
			'type' => 'application/json',
		]);

		return $result !== false;
	}

	private function save_to_linkding(string $url, string $title, array $config): bool {
		$base_url = rtrim($config['url'] ?? '', '/');
		$api_token = $config['api_token'] ?? '';

		if (empty($base_url) || empty($api_token)) return false;

		$result = UrlHelper::fetch([
			'url' => "$base_url/api/bookmarks/",
			'timeout' => 15,
			'post_query' => json_encode([
				'url' => $url,
				'title' => $title,
			]),
			'type' => 'application/json',
			'extra_headers' => ["Authorization: Token $api_token"],
		]);

		return $result !== false;
	}

	private function save_to_instapaper(string $url, array $config): bool {
		$username = $config['username'] ?? '';
		$password = $config['password'] ?? '';

		if (empty($username)) return false;

		$result = UrlHelper::fetch([
			'url' => 'https://www.instapaper.com/api/add',
			'timeout' => 15,
			'post_query' => http_build_query([
				'url' => $url,
				'username' => $username,
				'password' => $password,
			]),
		]);

		return $result !== false;
	}

	/**
	 * Liste der aktiven Dienste zurückgeben (für JS-Menü).
	 */
	function get_services(): void {
		$enabled = [];

		foreach ($this->services as $key => $label) {
			$config = $this->host->get($this, "service_$key", []);
			if (is_array($config) && !empty($config['enabled'])) {
				$enabled[] = ["id" => $key, "label" => $label];
			}
		}

		print json_encode($enabled);
	}

	function hook_prefs_tab($args) {
		if ($args != "prefPrefs") return;

		$wallabag = $this->host->get($this, "service_wallabag", []);
		$pocket = $this->host->get($this, "service_pocket", []);
		$linkding = $this->host->get($this, "service_linkding", []);
		$instapaper = $this->host->get($this, "service_instapaper", []);

		?>
		<div dojoType="dijit.layout.AccordionPane"
			title="<i class='material-icons'>bookmark_add</i> <?= __('Speichern unter...') ?>">

			<form dojoType="dijit.form.Form">
				<?= \Controls\pluginhandler_tags($this, "save") ?>

				<script type="dojo/method" event="onSubmit" args="evt">
					evt.preventDefault();
					if (this.validate()) {
						Notify.progress('Einstellungen werden gespeichert...', true);
						xhr.post("backend.php", this.getValues(), (reply) => {
							Notify.info(reply);
						})
					}
				</script>

				<!-- Wallabag -->
				<header>Wallabag</header>
				<fieldset>
					<label>
						<input dojoType="dijit.form.CheckBox" name="wallabag_enabled"
							type="checkbox" <?= !empty($wallabag['enabled']) ? 'checked' : '' ?>>
						<?= __('Aktiviert') ?>
					</label>
				</fieldset>
				<fieldset>
					<label><?= __('Server-URL:') ?></label>
					<input dojoType="dijit.form.TextBox" name="wallabag_url"
						style="width: 400px"
						placeholder="https://wallabag.example.com"
						value="<?= htmlspecialchars($wallabag['url'] ?? '') ?>">
				</fieldset>
				<fieldset>
					<label><?= __('Client-ID:') ?></label>
					<input dojoType="dijit.form.TextBox" name="wallabag_client_id"
						style="width: 300px"
						value="<?= htmlspecialchars($wallabag['client_id'] ?? '') ?>">
				</fieldset>
				<fieldset>
					<label><?= __('Client-Secret:') ?></label>
					<input dojoType="dijit.form.TextBox" name="wallabag_client_secret"
						type="password" style="width: 300px"
						value="<?= htmlspecialchars($wallabag['client_secret'] ?? '') ?>">
				</fieldset>
				<fieldset>
					<label><?= __('Benutzername:') ?></label>
					<input dojoType="dijit.form.TextBox" name="wallabag_username"
						style="width: 200px"
						value="<?= htmlspecialchars($wallabag['username'] ?? '') ?>">
				</fieldset>
				<fieldset>
					<label><?= __('Passwort:') ?></label>
					<input dojoType="dijit.form.TextBox" name="wallabag_password"
						type="password" style="width: 200px"
						value="<?= htmlspecialchars($wallabag['password'] ?? '') ?>">
				</fieldset>

				<hr/>

				<!-- Pocket -->
				<header>Pocket</header>
				<fieldset>
					<label>
						<input dojoType="dijit.form.CheckBox" name="pocket_enabled"
							type="checkbox" <?= !empty($pocket['enabled']) ? 'checked' : '' ?>>
						<?= __('Aktiviert') ?>
					</label>
				</fieldset>
				<fieldset>
					<label><?= __('Consumer Key:') ?></label>
					<input dojoType="dijit.form.TextBox" name="pocket_consumer_key"
						style="width: 300px"
						value="<?= htmlspecialchars($pocket['consumer_key'] ?? '') ?>">
				</fieldset>
				<fieldset>
					<label><?= __('Access Token:') ?></label>
					<input dojoType="dijit.form.TextBox" name="pocket_access_token"
						type="password" style="width: 300px"
						value="<?= htmlspecialchars($pocket['access_token'] ?? '') ?>">
				</fieldset>

				<hr/>

				<!-- Linkding -->
				<header>Linkding</header>
				<fieldset>
					<label>
						<input dojoType="dijit.form.CheckBox" name="linkding_enabled"
							type="checkbox" <?= !empty($linkding['enabled']) ? 'checked' : '' ?>>
						<?= __('Aktiviert') ?>
					</label>
				</fieldset>
				<fieldset>
					<label><?= __('Server-URL:') ?></label>
					<input dojoType="dijit.form.TextBox" name="linkding_url"
						style="width: 400px"
						placeholder="https://linkding.example.com"
						value="<?= htmlspecialchars($linkding['url'] ?? '') ?>">
				</fieldset>
				<fieldset>
					<label><?= __('API-Token:') ?></label>
					<input dojoType="dijit.form.TextBox" name="linkding_api_token"
						type="password" style="width: 300px"
						value="<?= htmlspecialchars($linkding['api_token'] ?? '') ?>">
				</fieldset>

				<hr/>

				<!-- Instapaper -->
				<header>Instapaper</header>
				<fieldset>
					<label>
						<input dojoType="dijit.form.CheckBox" name="instapaper_enabled"
							type="checkbox" <?= !empty($instapaper['enabled']) ? 'checked' : '' ?>>
						<?= __('Aktiviert') ?>
					</label>
				</fieldset>
				<fieldset>
					<label><?= __('Benutzername:') ?></label>
					<input dojoType="dijit.form.TextBox" name="instapaper_username"
						style="width: 300px"
						value="<?= htmlspecialchars($instapaper['username'] ?? '') ?>">
				</fieldset>
				<fieldset>
					<label><?= __('Passwort:') ?></label>
					<input dojoType="dijit.form.TextBox" name="instapaper_password"
						type="password" style="width: 200px"
						value="<?= htmlspecialchars($instapaper['password'] ?? '') ?>">
				</fieldset>

				<hr/>
				<?= \Controls\submit_tag(__("Speichern")) ?>
			</form>
		</div>
		<?php
	}

	function save(): void {
		$this->host->set($this, "service_wallabag", [
			'enabled' => checkbox_to_sql_bool($_POST["wallabag_enabled"] ?? ""),
			'url' => clean($_POST["wallabag_url"] ?? ""),
			'client_id' => clean($_POST["wallabag_client_id"] ?? ""),
			'client_secret' => clean($_POST["wallabag_client_secret"] ?? ""),
			'username' => clean($_POST["wallabag_username"] ?? ""),
			'password' => clean($_POST["wallabag_password"] ?? ""),
		]);

		$this->host->set($this, "service_pocket", [
			'enabled' => checkbox_to_sql_bool($_POST["pocket_enabled"] ?? ""),
			'consumer_key' => clean($_POST["pocket_consumer_key"] ?? ""),
			'access_token' => clean($_POST["pocket_access_token"] ?? ""),
		]);

		$this->host->set($this, "service_linkding", [
			'enabled' => checkbox_to_sql_bool($_POST["linkding_enabled"] ?? ""),
			'url' => clean($_POST["linkding_url"] ?? ""),
			'api_token' => clean($_POST["linkding_api_token"] ?? ""),
		]);

		$this->host->set($this, "service_instapaper", [
			'enabled' => checkbox_to_sql_bool($_POST["instapaper_enabled"] ?? ""),
			'username' => clean($_POST["instapaper_username"] ?? ""),
			'password' => clean($_POST["instapaper_password"] ?? ""),
		]);

		echo __("Dienst-Konfiguration gespeichert.");
	}

	function api_version() {
		return 2;
	}
}
