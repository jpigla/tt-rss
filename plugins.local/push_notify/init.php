<?php
class Push_Notify extends Plugin {

	/** @var PluginHost $host */
	private $host;

	function about() {
		return [1.0,
			"Push-Benachrichtigungen über ntfy.sh, Gotify oder Pushover bei Filter-Auslösungen",
			"tt-ss",
			false];
	}

	function init($host) {
		$this->host = $host;

		$host->add_hook($host::HOOK_FILTER_TRIGGERED, $this);
		$host->add_hook($host::HOOK_PREFS_TAB, $this);
	}

	function get_css() {
		return file_get_contents(__DIR__ . "/push_notify.css");
	}

	/**
	 * @param int $feed_id
	 * @param int $owner_uid
	 * @param array<string, mixed> $article
	 * @param array<string, mixed> $matched_filters
	 * @param array<string, string|bool|int> $matched_rules
	 * @param array<int, array{type: string, param: string}> $article_filter_actions
	 */
	function hook_filter_triggered($feed_id, $owner_uid, $article, $matched_filters, $matched_rules, $article_filter_actions): void {
		$service = $this->host->get($this, "service", "");

		if (empty($service)) return;

		$title = "TT-RSS Filter";
		$filter_names = array_map(function($f) {
			return $f['title'] ?? ('Filter #' . ($f['id'] ?? '?'));
		}, $matched_filters);

		$message = ($article['title'] ?? 'Unbekannter Artikel');
		if (!empty($filter_names)) {
			$title = "Filter: " . implode(', ', $filter_names);
		}
		if (!empty($article['link'])) {
			$message .= "\n" . $article['link'];
		}

		$this->send_notification($title, $message);
	}

	/**
	 * Benachrichtigung über den konfigurierten Dienst senden.
	 */
	private function send_notification(string $title, string $message): bool {
		$service = $this->host->get($this, "service", "");

		switch ($service) {
			case 'ntfy':
				return $this->send_ntfy($title, $message);
			case 'gotify':
				return $this->send_gotify($title, $message);
			case 'pushover':
				return $this->send_pushover($title, $message);
			default:
				return false;
		}
	}

	private function send_ntfy(string $title, string $message): bool {
		$server_url = rtrim($this->host->get($this, "ntfy_server_url", "https://ntfy.sh"), '/');
		$topic = $this->host->get($this, "ntfy_topic", "");

		if (empty($topic)) return false;

		$url = "$server_url/$topic";

		$result = UrlHelper::fetch([
			'url' => $url,
			'timeout' => 15,
			'post_query' => $message,
			'type' => 'text/plain',
			'extra_headers' => [
				"Title: $title",
				"Priority: default",
				"Tags: rss",
			],
		]);

		return $result !== false;
	}

	private function send_gotify(string $title, string $message): bool {
		$server_url = rtrim($this->host->get($this, "gotify_server_url", ""), '/');
		$app_token = $this->host->get($this, "gotify_app_token", "");

		if (empty($server_url) || empty($app_token)) return false;

		$url = "$server_url/message?token=" . urlencode($app_token);

		$payload = json_encode([
			"title" => $title,
			"message" => $message,
			"priority" => 5,
		]);

		$result = UrlHelper::fetch([
			'url' => $url,
			'timeout' => 15,
			'post_query' => $payload,
			'type' => 'application/json',
		]);

		return $result !== false;
	}

	private function send_pushover(string $title, string $message): bool {
		$user_key = $this->host->get($this, "pushover_user_key", "");
		$api_token = $this->host->get($this, "pushover_api_token", "");

		if (empty($user_key) || empty($api_token)) return false;

		$url = "https://api.pushover.net/1/messages.json";

		$post_data = http_build_query([
			"token" => $api_token,
			"user" => $user_key,
			"title" => $title,
			"message" => $message,
		]);

		$result = UrlHelper::fetch([
			'url' => $url,
			'timeout' => 15,
			'post_query' => $post_data,
		]);

		return $result !== false;
	}

	function hook_prefs_tab($args) {
		if ($args != "prefPrefs") return;

		$service = $this->host->get($this, "service", "");
		$ntfy_server_url = htmlspecialchars($this->host->get($this, "ntfy_server_url", "https://ntfy.sh"));
		$ntfy_topic = htmlspecialchars($this->host->get($this, "ntfy_topic", ""));
		$gotify_server_url = htmlspecialchars($this->host->get($this, "gotify_server_url", ""));
		$gotify_app_token = htmlspecialchars($this->host->get($this, "gotify_app_token", ""));
		$pushover_user_key = htmlspecialchars($this->host->get($this, "pushover_user_key", ""));
		$pushover_api_token = htmlspecialchars($this->host->get($this, "pushover_api_token", ""));

		?>
		<div dojoType="dijit.layout.AccordionPane"
			title="<i class='material-icons'>notifications_active</i> <?= __('Push-Benachrichtigungen') ?>">

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

				<header><?= __('Dienst auswählen') ?></header>

				<fieldset>
					<label><?= __('Benachrichtigungsdienst:') ?></label>
					<select dojoType="dijit.form.Select" name="service" id="push_notify_service"
						onchange="
							document.getElementById('push_notify_ntfy').style.display = this.value === 'ntfy' ? '' : 'none';
							document.getElementById('push_notify_gotify').style.display = this.value === 'gotify' ? '' : 'none';
							document.getElementById('push_notify_pushover').style.display = this.value === 'pushover' ? '' : 'none';
						">
						<option value="" <?= $service === '' ? 'selected' : '' ?>><?= __('-- Deaktiviert --') ?></option>
						<option value="ntfy" <?= $service === 'ntfy' ? 'selected' : '' ?>>ntfy.sh</option>
						<option value="gotify" <?= $service === 'gotify' ? 'selected' : '' ?>>Gotify</option>
						<option value="pushover" <?= $service === 'pushover' ? 'selected' : '' ?>>Pushover</option>
					</select>
				</fieldset>

				<!-- ntfy.sh -->
				<div id="push_notify_ntfy" style="<?= $service === 'ntfy' ? '' : 'display: none' ?>">
					<header>ntfy.sh</header>
					<fieldset>
						<label><?= __('Server-URL:') ?></label>
						<input dojoType="dijit.form.TextBox" name="ntfy_server_url"
							style="width: 400px"
							placeholder="https://ntfy.sh"
							value="<?= $ntfy_server_url ?>">
					</fieldset>
					<fieldset>
						<label><?= __('Topic:') ?></label>
						<input dojoType="dijit.form.TextBox" name="ntfy_topic"
							style="width: 300px"
							placeholder="mein-ttrss"
							value="<?= $ntfy_topic ?>">
					</fieldset>
				</div>

				<!-- Gotify -->
				<div id="push_notify_gotify" style="<?= $service === 'gotify' ? '' : 'display: none' ?>">
					<header>Gotify</header>
					<fieldset>
						<label><?= __('Server-URL:') ?></label>
						<input dojoType="dijit.form.TextBox" name="gotify_server_url"
							style="width: 400px"
							placeholder="https://gotify.example.com"
							value="<?= $gotify_server_url ?>">
					</fieldset>
					<fieldset>
						<label><?= __('App-Token:') ?></label>
						<input dojoType="dijit.form.TextBox" name="gotify_app_token"
							type="password"
							style="width: 300px"
							value="<?= $gotify_app_token ?>">
					</fieldset>
				</div>

				<!-- Pushover -->
				<div id="push_notify_pushover" style="<?= $service === 'pushover' ? '' : 'display: none' ?>">
					<header>Pushover</header>
					<fieldset>
						<label><?= __('User Key:') ?></label>
						<input dojoType="dijit.form.TextBox" name="pushover_user_key"
							style="width: 300px"
							value="<?= $pushover_user_key ?>">
					</fieldset>
					<fieldset>
						<label><?= __('API Token:') ?></label>
						<input dojoType="dijit.form.TextBox" name="pushover_api_token"
							type="password"
							style="width: 300px"
							value="<?= $pushover_api_token ?>">
					</fieldset>
				</div>

				<hr/>

				<fieldset>
					<?= \Controls\submit_tag(__("Speichern")) ?>

					<button dojoType="dijit.form.Button" type="button"
						onclick="
							Notify.progress('Sende Testbenachrichtigung...', true);
							xhr.json('backend.php', {
								op: 'pluginhandler',
								plugin: 'push_notify',
								method: 'test_notification'
							}, (reply) => {
								if (reply.success) {
									Notify.info(reply.message);
								} else {
									Notify.error(reply.message);
								}
							});
						">
						<i class="material-icons">send</i> <?= __('Test senden') ?>
					</button>
				</fieldset>
			</form>
		</div>
		<?php
	}

	function save(): void {
		$this->host->set($this, "service", clean($_POST["service"] ?? ""));
		$this->host->set($this, "ntfy_server_url", clean($_POST["ntfy_server_url"] ?? "https://ntfy.sh"));
		$this->host->set($this, "ntfy_topic", clean($_POST["ntfy_topic"] ?? ""));
		$this->host->set($this, "gotify_server_url", clean($_POST["gotify_server_url"] ?? ""));
		$this->host->set($this, "gotify_app_token", clean($_POST["gotify_app_token"] ?? ""));
		$this->host->set($this, "pushover_user_key", clean($_POST["pushover_user_key"] ?? ""));
		$this->host->set($this, "pushover_api_token", clean($_POST["pushover_api_token"] ?? ""));

		echo __("Push-Benachrichtigungen konfiguriert.");
	}

	function test_notification(): void {
		$result = $this->send_notification(
			"TT-RSS Test",
			"Dies ist eine Testbenachrichtigung von TT-RSS."
		);

		print json_encode([
			"success" => $result,
			"message" => $result
				? __("Testbenachrichtigung erfolgreich gesendet.")
				: __("Fehler beim Senden. Bitte Konfiguration prüfen."),
		]);
	}

	function api_version() {
		return 2;
	}
}
