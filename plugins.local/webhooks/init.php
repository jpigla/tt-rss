<?php
class Webhooks extends Plugin {

	/** @var PluginHost $host */
	private $host;

	function about() {
		return [1.0,
			"HTTP-Webhooks bei Filter-Aktionen, Stern-Markierungen und Veröffentlichungen senden",
			"tt-ss",
			false];
	}

	function init($host) {
		$this->host = $host;

		$host->add_hook($host::HOOK_FILTER_TRIGGERED, $this);
		$host->add_hook($host::HOOK_PREFS_TAB, $this);
	}

	function get_css() {
		return file_get_contents(__DIR__ . "/webhooks.css");
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
		$enabled_events = $this->host->get($this, "enabled_events", []);

		if (!is_array($enabled_events) || !in_array("filter_triggered", $enabled_events)) {
			return;
		}

		$payload = [
			"event_type" => "filter_triggered",
			"article" => [
				"title" => $article['title'] ?? '',
				"link" => $article['link'] ?? '',
				"content_snippet" => mb_substr(strip_tags($article['content'] ?? ''), 0, 300),
			],
			"feed_id" => $feed_id,
			"timestamp" => date('c'),
			"filters" => array_map(function($f) {
				return $f['title'] ?? ('Filter #' . ($f['id'] ?? '?'));
			}, $matched_filters),
			"actions" => array_map(function($a) {
				return $a['type'] ?? 'unbekannt';
			}, $article_filter_actions),
		];

		$this->send_webhook($payload);
	}

	/**
	 * Webhook-Payload an die konfigurierte URL senden.
	 * @param array<string, mixed> $payload
	 * @return bool
	 */
	private function send_webhook(array $payload): bool {
		$url = $this->host->get($this, "webhook_url", "");

		if (empty($url)) {
			return false;
		}

		$secret = $this->host->get($this, "webhook_secret", "");
		$json = json_encode($payload, JSON_UNESCAPED_UNICODE);

		$extra_headers = ['Content-Type: application/json'];

		if (!empty($secret)) {
			$signature = hash_hmac('sha256', $json, $secret);
			$extra_headers[] = "X-Webhook-Signature: sha256=$signature";
		}

		$result = UrlHelper::fetch([
			'url' => $url,
			'timeout' => 15,
			'post_query' => $json,
			'type' => 'application/json',
			'extra_headers' => $extra_headers,
		]);

		return $result !== false;
	}

	function hook_prefs_tab($args) {
		if ($args != "prefPrefs") return;

		$webhook_url = htmlspecialchars($this->host->get($this, "webhook_url", ""));
		$webhook_secret = htmlspecialchars($this->host->get($this, "webhook_secret", ""));
		$enabled_events = $this->host->get($this, "enabled_events", []);
		if (!is_array($enabled_events)) $enabled_events = [];

		$filter_checked = in_array("filter_triggered", $enabled_events) ? "checked" : "";

		?>
		<div dojoType="dijit.layout.AccordionPane"
			title="<i class='material-icons'>webhook</i> <?= __('Webhooks') ?>">

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

				<header><?= __('Webhook-Konfiguration') ?></header>

				<fieldset>
					<label><?= __('Webhook-URL:') ?></label>
					<input dojoType="dijit.form.ValidationTextBox"
						name="webhook_url"
						style="width: 500px"
						placeholder="https://example.com/webhook"
						value="<?= $webhook_url ?>">
				</fieldset>

				<fieldset>
					<label><?= __('Geheimer Schlüssel (optional):') ?></label>
					<input dojoType="dijit.form.TextBox"
						name="webhook_secret"
						type="password"
						style="width: 300px"
						placeholder="HMAC-Signatur-Schlüssel"
						value="<?= $webhook_secret ?>">
				</fieldset>

				<fieldset>
					<label><?= __('Aktive Ereignisse:') ?></label>
					<div class="webhooks-events">
						<label>
							<input dojoType="dijit.form.CheckBox"
								name="event_filter_triggered"
								type="checkbox"
								<?= $filter_checked ?>>
							<?= __('Filter ausgelöst') ?>
						</label>
					</div>
				</fieldset>

				<hr/>

				<fieldset>
					<?= \Controls\submit_tag(__("Speichern")) ?>

					<button dojoType="dijit.form.Button" type="button"
						onclick="
							Notify.progress('Teste Webhook...', true);
							xhr.json('backend.php', {
								op: 'pluginhandler',
								plugin: 'webhooks',
								method: 'test_webhook'
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
		$webhook_url = clean($_POST["webhook_url"] ?? "");
		$webhook_secret = clean($_POST["webhook_secret"] ?? "");

		// URL-Validierung: nur https erlauben
		if (!empty($webhook_url) && !preg_match('#^https://#i', $webhook_url)) {
			echo __("Webhook-URL muss HTTPS verwenden.");
			return;
		}

		$enabled_events = [];
		if (checkbox_to_sql_bool($_POST["event_filter_triggered"] ?? "")) {
			$enabled_events[] = "filter_triggered";
		}

		$this->host->set($this, "webhook_url", $webhook_url);
		$this->host->set($this, "webhook_secret", $webhook_secret);
		$this->host->set($this, "enabled_events", $enabled_events);

		echo __("Webhook-Einstellungen gespeichert.");
	}

	function test_webhook(): void {
		$payload = [
			"event_type" => "test",
			"message" => "Dies ist ein Test-Webhook von TT-RSS.",
			"timestamp" => date('c'),
		];

		$result = $this->send_webhook($payload);

		print json_encode([
			"success" => $result,
			"message" => $result
				? __("Test-Webhook erfolgreich gesendet.")
				: __("Fehler beim Senden des Test-Webhooks. Bitte URL prüfen."),
		]);
	}

	function api_version() {
		return 2;
	}
}
