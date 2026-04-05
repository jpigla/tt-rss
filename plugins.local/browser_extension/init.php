<?php
class Browser_Extension extends Plugin {

	/** @var PluginHost $host */
	private $host;

	function about() {
		return [1.0,
			"API-Endpunkt für Browser-Erweiterungen zum Speichern von Artikeln",
			"tt-ss",
			false];
	}

	function init($host) {
		$this->host = $host;

		$host->add_hook($host::HOOK_PREFS_TAB, $this);
	}

	/**
	 * Öffentliche Methoden (ohne Login).
	 */
	function is_public_method($method): bool {
		return in_array($method, ["save_article", "check"]);
	}

	/**
	 * CSRF-Prüfung für öffentliche Methoden überspringen.
	 */
	function csrf_ignore($method): bool {
		return in_array($method, ["save_article", "check"]);
	}

	/**
	 * Health-Check-Endpunkt.
	 */
	function check(): void {
		header('Content-Type: application/json');
		print json_encode([
			'status' => 'ok',
			'version' => '1.0'
		]);
	}

	/**
	 * Artikel über API speichern.
	 */
	function save_article(): void {
		header('Content-Type: application/json');
		header('Access-Control-Allow-Origin: ' . Config::get_self_url());
		header('Access-Control-Allow-Methods: POST, OPTIONS');
		header('Access-Control-Allow-Headers: Content-Type');

		if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
			http_response_code(200);
			return;
		}

		// JSON-Body lesen
		$input = json_decode(file_get_contents('php://input'), true);
		if (!$input) {
			$input = $_REQUEST;
		}

		$api_key = $input['api_key'] ?? '';
		$url = strip_tags($input['url'] ?? '');
		$title = strip_tags($input['title'] ?? '');
		$content = $input['content'] ?? '';

		if (empty($api_key)) {
			print json_encode(['status' => 'error', 'message' => 'API-Schlüssel fehlt.']);
			return;
		}

		if (empty($url)) {
			print json_encode(['status' => 'error', 'message' => 'URL ist erforderlich.']);
			return;
		}

		// API-Schlüssel validieren und Benutzer finden
		$owner_uid = $this->validate_api_key($api_key);
		if (!$owner_uid) {
			http_response_code(403);
			print json_encode(['status' => 'error', 'message' => 'Ungültiger API-Schlüssel.']);
			return;
		}

		// Leeren Titel durch URL ersetzen
		if (empty($title)) {
			$title = $url;
		}

		try {
			Article::_create_published_article($title, $url, $content, '', $owner_uid);
			print json_encode(['status' => 'ok']);
		} catch (Exception $e) {
			Debug::log("browser_extension: Fehler beim Speichern: " . $e->getMessage(), Debug::LOG_VERBOSE);
			print json_encode(['status' => 'error', 'message' => 'Fehler beim Speichern des Artikels.']);
		}
	}

	/**
	 * Validiert den API-Schlüssel und gibt die owner_uid zurück.
	 * @param string $api_key
	 * @return int|false
	 */
	private function validate_api_key(string $api_key) {
		// Alle Benutzer-IDs durchsuchen, die dieses Plugin verwenden
		$escaped_key = str_replace(['%', '_'], ['\%', '\_'], $api_key);
		$sth = $this->pdo->prepare("SELECT owner_uid FROM ttrss_plugin_storage
			WHERE name = 'browser_extension' AND content LIKE ? ESCAPE '\\'");
		$sth->execute(['%' . $escaped_key . '%']);

		while ($row = $sth->fetch(PDO::FETCH_ASSOC)) {
			$uid = (int) $row['owner_uid'];
			// Plugin-Storage für den Benutzer laden und Key prüfen
			$sth2 = $this->pdo->prepare("SELECT content FROM ttrss_plugin_storage
				WHERE name = 'browser_extension' AND owner_uid = ?");
			$sth2->execute([$uid]);
			$storage_row = $sth2->fetch(PDO::FETCH_ASSOC);

			if ($storage_row) {
				$storage = json_decode($storage_row['content'], true);
				if (is_array($storage) && ($storage['api_key'] ?? '') === $api_key) {
					return $uid;
				}
			}
		}

		return false;
	}

	/**
	 * Neuen API-Schlüssel generieren (AJAX-Handler).
	 */
	function generate_key(): void {
		$api_key = bin2hex(random_bytes(32));
		$this->host->set($this, 'api_key', $api_key);

		print json_encode([
			'status' => 'ok',
			'api_key' => $api_key
		]);
	}

	/**
	 * Aktuellen API-Schlüssel abrufen (AJAX-Handler).
	 */
	function get_key(): void {
		$api_key = $this->host->get($this, 'api_key', '');

		print json_encode([
			'api_key' => $api_key
		]);
	}

	/**
	 * Einstellungs-Tab.
	 */
	function hook_prefs_tab($args) {
		if ($args != "prefPrefs") return;

		$api_key = $this->host->get($this, 'api_key', '');
		$base_url = get_self_url_prefix();
		$endpoint = htmlspecialchars($base_url . "/public.php?op=pluginhandler&plugin=browser_extension&pmethod=save_article");

		$bookmarklet_js = "javascript:void(fetch('" . $base_url . "/public.php?op=pluginhandler&plugin=browser_extension&pmethod=save_article',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({url:location.href,title:document.title,api_key:'" . htmlspecialchars($api_key) . "'})}).then(r=>r.json()).then(d=>alert(d.status==='ok'?'Gespeichert!':'Fehler: '+d.message)).catch(e=>alert('Fehler: '+e)))";
		?>
		<div dojoType="dijit.layout.AccordionPane"
			title="<i class='material-icons'>extension</i> <?= __('Browser-Erweiterung') ?>">

			<h3><?= __('API-Schlüssel') ?></h3>

			<div id="be-api-key-section">
				<?php if ($api_key): ?>
					<fieldset>
						<label><?= __('Aktueller Schlüssel:') ?></label>
						<code id="be-current-key" style="user-select: all; padding: 4px 8px; background: var(--bg-color-secondary, #f5f5f5); border-radius: 4px;"><?= htmlspecialchars($api_key) ?></code>
					</fieldset>
				<?php else: ?>
					<p><?= __('Kein API-Schlüssel vorhanden.') ?></p>
				<?php endif; ?>

				<button dojoType="dijit.form.Button" type="button"
					onclick="Plugins.Browser_Extension.generateKey()">
					<i class="material-icons">vpn_key</i> <?= $api_key ? __('Schlüssel erneuern') : __('Schlüssel generieren') ?>
				</button>
			</div>

			<hr/>

			<h3><?= __('API-Endpunkt') ?></h3>
			<fieldset>
				<label><?= __('URL:') ?></label>
				<code style="user-select: all; word-break: break-all; padding: 4px 8px; background: var(--bg-color-secondary, #f5f5f5); border-radius: 4px;"><?= $endpoint ?></code>
			</fieldset>

			<hr/>

			<?php if ($api_key): ?>
			<h3><?= __('Bookmarklet') ?></h3>
			<p><?= __('Ziehe den folgenden Link in deine Lesezeichen-Leiste:') ?></p>
			<label class="dijitButton">
				<a href="<?= htmlspecialchars($bookmarklet_js) ?>"><?= __('In TT-RSS speichern') ?></a>
			</label>
			<hr/>
			<?php endif; ?>

			<h3><?= __('Anleitung für Browser-Erweiterungen') ?></h3>
			<div style="padding: 8px; background: var(--bg-color-secondary, #f5f5f5); border-radius: 4px;">
				<p><?= __('Sende einen POST-Request an den API-Endpunkt mit folgendem JSON-Body:') ?></p>
				<pre style="margin: 8px 0; padding: 8px; background: var(--bg-color, #fff); border-radius: 4px;">{
  "url": "https://example.com/article",
  "title": "Artikeltitel",
  "content": "Optionaler Inhalt (HTML)",
  "api_key": "DEIN_API_SCHLÜSSEL"
}</pre>
				<p><?= __('Antwort bei Erfolg:') ?> <code>{"status": "ok"}</code></p>
				<p><?= __('Antwort bei Fehler:') ?> <code>{"status": "error", "message": "..."}</code></p>
			</div>
		</div>

		<script type="text/javascript">
			if (typeof Plugins === "undefined") window.Plugins = {};
			Plugins.Browser_Extension = {
				generateKey: function() {
					if (!confirm("<?= __('Neuen API-Schlüssel generieren? Der alte wird ungültig.') ?>")) return;

					xhr.json("backend.php", {
						op: "pluginhandler",
						plugin: "browser_extension",
						method: "generate_key"
					}, function(reply) {
						if (reply.status === "ok") {
							Notify.info("<?= __('Neuer Schlüssel generiert. Seite wird neu geladen...') ?>");
							window.setTimeout(function() { location.reload(); }, 1000);
						} else {
							Notify.error("<?= __('Fehler beim Generieren des Schlüssels.') ?>");
						}
					});
				}
			};
		</script>

		<?php
	}

	function get_css() {
		return file_get_contents(__DIR__ . "/browser_extension.css");
	}

	function api_version() {
		return 2;
	}
}
