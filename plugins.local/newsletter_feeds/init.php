<?php
class Newsletter_Feeds extends Plugin {

	/** @var PluginHost $host */
	private $host;

	function about() {
		return [1.0,
			"Newsletter per IMAP abrufen und als veröffentlichte Artikel anlegen",
			"tt-ss",
			false];
	}

	function init($host) {
		$this->host = $host;

		$host->add_hook($host::HOOK_HOUSE_KEEPING, $this);
		$host->add_hook($host::HOOK_PREFS_TAB, $this);

		$m = new Db_Migrations();
		$m->initialize_for_plugin($this);
		$m->migrate();
	}

	function get_css() {
		return file_get_contents(__DIR__ . "/newsletter_feeds.css");
	}

	/**
	 * Periodisch IMAP-Postfächer prüfen.
	 */
	function hook_house_keeping(): void {
		if (!function_exists('imap_open')) {
			return;
		}

		$sth = $this->pdo->prepare("SELECT * FROM ttrss_plugin_newsletter_inboxes
			WHERE last_checked IS NULL
			   OR last_checked < NOW() - INTERVAL '5 minutes'");
		$sth->execute();

		while ($row = $sth->fetch()) {
			$this->check_inbox($row);
		}
	}

	/**
	 * Ein IMAP-Postfach prüfen und neue E-Mails als Artikel anlegen.
	 * @param array<string, mixed> $inbox
	 */
	private function check_inbox(array $inbox): void {
		$server = $inbox['imap_server'];
		$port = (int)$inbox['imap_port'];
		$user = $inbox['imap_user'];
		$pass = $inbox['imap_pass'];
		$folder = $inbox['imap_folder'];
		$owner_uid = (int)$inbox['owner_uid'];

		$validate_cert = $this->host->get($this, "validate_cert", true);
		$cert_flag = $validate_cert ? "" : "/novalidate-cert";
		$mailbox = "{" . $server . ":" . $port . "/imap/ssl" . $cert_flag . "}" . $folder;

		$conn = @imap_open($mailbox, $user, $pass);
		if (!$conn) {
			// Zeitstempel trotzdem aktualisieren
			$upd = $this->pdo->prepare("UPDATE ttrss_plugin_newsletter_inboxes
				SET last_checked = NOW() WHERE id = ?");
			$upd->execute([$inbox['id']]);
			return;
		}

		// Ungelesene Nachrichten seit letzter Prüfung suchen
		$since = $inbox['last_checked']
			? date('d-M-Y', strtotime($inbox['last_checked']))
			: date('d-M-Y', strtotime('-1 day'));

		$messages = @imap_search($conn, 'UNSEEN SINCE "' . $since . '"');

		if ($messages) {
			foreach ($messages as $msgno) {
				$header = @imap_headerinfo($conn, $msgno);
				if (!$header) continue;

				$subject = isset($header->subject) ? $this->decode_mime($header->subject) : __('(Kein Betreff)');
				$from = isset($header->fromaddress) ? $this->decode_mime($header->fromaddress) : '';

				// HTML-Body bevorzugen, sonst Text
				$body = $this->get_mail_body($conn, $msgno);

				// Als veröffentlichten Artikel anlegen
				$url = 'mailto:' . ($inbox['email_address'] ?? '');
				Article::_create_published_article($subject, $url, $body, '', $owner_uid);

				// Als gelesen markieren
				@imap_setflag_full($conn, (string)$msgno, '\\Seen');
			}
		}

		@imap_close($conn);

		$upd = $this->pdo->prepare("UPDATE ttrss_plugin_newsletter_inboxes
			SET last_checked = NOW() WHERE id = ?");
		$upd->execute([$inbox['id']]);
	}

	/**
	 * Mail-Body extrahieren (HTML bevorzugt).
	 * @param resource $conn IMAP-Verbindung
	 * @param int $msgno Nachrichtennummer
	 * @return string
	 */
	private function get_mail_body($conn, int $msgno): string {
		$structure = @imap_fetchstructure($conn, $msgno);
		if (!$structure) {
			return @imap_body($conn, $msgno) ?: '';
		}

		// HTML-Teil suchen
		if (isset($structure->parts) && is_array($structure->parts)) {
			foreach ($structure->parts as $partno => $part) {
				if ($part->subtype === 'HTML') {
					$body = @imap_fetchbody($conn, $msgno, (string)($partno + 1));
					return $this->decode_body($body, $part->encoding);
				}
			}
			// Fallback: Text-Teil
			foreach ($structure->parts as $partno => $part) {
				if ($part->subtype === 'PLAIN') {
					$body = @imap_fetchbody($conn, $msgno, (string)($partno + 1));
					$text = $this->decode_body($body, $part->encoding);
					return nl2br(htmlspecialchars($text));
				}
			}
		}

		// Einfache Nachricht
		$body = @imap_body($conn, $msgno) ?: '';
		if (isset($structure->subtype) && $structure->subtype === 'HTML') {
			return $this->decode_body($body, $structure->encoding ?? 0);
		}
		return nl2br(htmlspecialchars($this->decode_body($body, $structure->encoding ?? 0)));
	}

	/**
	 * Body basierend auf Encoding dekodieren.
	 * @param string $body
	 * @param int $encoding
	 * @return string
	 */
	private function decode_body(string $body, int $encoding): string {
		switch ($encoding) {
			case 3: // BASE64
				return base64_decode($body);
			case 4: // QUOTED-PRINTABLE
				return quoted_printable_decode($body);
			default:
				return $body;
		}
	}

	/**
	 * MIME-Header dekodieren.
	 * @param string $str
	 * @return string
	 */
	private function decode_mime(string $str): string {
		$elements = imap_mime_header_decode($str);
		$result = '';
		foreach ($elements as $el) {
			$result .= $el->text;
		}
		return $result;
	}

	/**
	 * Einstellungsseite: IMAP-Verbindung konfigurieren.
	 */
	function hook_prefs_tab($args) {
		if ($args != "prefFeeds") return;

		$has_imap = function_exists('imap_open');

		$sth = $this->pdo->prepare("SELECT * FROM ttrss_plugin_newsletter_inboxes
			WHERE owner_uid = ? ORDER BY created_at DESC");
		$sth->execute([$_SESSION['uid']]);

		?>
		<div dojoType="dijit.layout.AccordionPane"
			title="<i class='material-icons'>email</i> <?= __('Newsletter-Feeds') ?>">

			<?php if (!$has_imap) { ?>
			<div class="nf-warning">
				<i class="material-icons">warning</i>
				<?= __('Die PHP-Erweiterung php-imap ist nicht installiert. Newsletter-Feeds können ohne diese Erweiterung nicht abgerufen werden.') ?>
			</div>
			<?php } ?>

			<form dojoType="dijit.form.Form">
				<?= \Controls\pluginhandler_tags($this, "save_config") ?>

				<script type="dojo/method" event="onSubmit" args="evt">
					evt.preventDefault();
					if (this.validate()) {
						Notify.progress('Speichere...', true);
						xhr.post("backend.php", this.getValues(), (reply) => {
							Notify.info(reply);
						})
					}
				</script>

				<fieldset>
					<label><?= __('E-Mail-Adresse:') ?></label>
					<input dojoType="dijit.form.TextBox" name="email_address"
						style="width: 300px"
						placeholder="newsletter@example.com">
				</fieldset>

				<fieldset>
					<label><?= __('IMAP-Server:') ?></label>
					<input dojoType="dijit.form.TextBox" name="imap_server"
						style="width: 300px"
						placeholder="imap.example.com">
				</fieldset>

				<fieldset>
					<label><?= __('IMAP-Port:') ?></label>
					<input dojoType="dijit.form.NumberSpinner" name="imap_port"
						value="993"
						constraints="{min:1,max:65535}">
				</fieldset>

				<fieldset>
					<label><?= __('IMAP-Benutzer:') ?></label>
					<input dojoType="dijit.form.TextBox" name="imap_user"
						style="width: 300px">
				</fieldset>

				<fieldset>
					<label><?= __('IMAP-Passwort:') ?></label>
					<input dojoType="dijit.form.TextBox" name="imap_pass"
						type="password"
						style="width: 300px">
				</fieldset>

				<fieldset>
					<label><?= __('IMAP-Ordner:') ?></label>
					<input dojoType="dijit.form.TextBox" name="imap_folder"
						value="INBOX"
						style="width: 200px">
				</fieldset>

				<hr/>

				<?= \Controls\submit_tag(__("Speichern")) ?>

				<button dojoType="dijit.form.Button" type="button"
					onclick="xhr.json('backend.php', {op: 'pluginhandler', plugin: 'newsletter_feeds', method: 'test_connection'}, (r) => { Notify.info(r.message || r.error); })">
					<?= __('Verbindung testen') ?>
				</button>

				<button dojoType="dijit.form.Button" type="button"
					onclick="xhr.json('backend.php', {op: 'pluginhandler', plugin: 'newsletter_feeds', method: 'check_now'}, (r) => { Notify.info(r.message || r.error); })">
					<?= __('Jetzt prüfen') ?>
				</button>
			</form>

			<h3><?= __('Konfigurierte Postfächer') ?></h3>
			<table width="100%">
				<tr class="title">
					<td><?= __('E-Mail') ?></td>
					<td><?= __('Server') ?></td>
					<td><?= __('Zuletzt geprüft') ?></td>
				</tr>
				<?php while ($row = $sth->fetch()) { ?>
				<tr>
					<td><?= htmlspecialchars($row['email_address']) ?></td>
					<td><?= htmlspecialchars($row['imap_server']) ?>:<?= (int)$row['imap_port'] ?></td>
					<td><?= htmlspecialchars($row['last_checked'] ?? __('Noch nicht')) ?></td>
				</tr>
				<?php } ?>
			</table>
		</div>
		<?php
	}

	/**
	 * IMAP-Einstellungen speichern.
	 */
	function save_config(): void {
		$email = clean($_REQUEST['email_address'] ?? '');
		$server = clean($_REQUEST['imap_server'] ?? '');
		$port = (int)clean($_REQUEST['imap_port'] ?? 993);
		$user = clean($_REQUEST['imap_user'] ?? '');
		$pass = clean($_REQUEST['imap_pass'] ?? '');
		$folder = clean($_REQUEST['imap_folder'] ?? 'INBOX');

		if (empty($email) || empty($server)) {
			echo __('E-Mail-Adresse und Server sind erforderlich.');
			return;
		}

		// Prüfen ob bereits vorhanden
		$sth = $this->pdo->prepare("SELECT id FROM ttrss_plugin_newsletter_inboxes
			WHERE email_address = ? AND owner_uid = ?");
		$sth->execute([$email, $_SESSION['uid']]);

		if ($existing = $sth->fetch()) {
			$upd = $this->pdo->prepare("UPDATE ttrss_plugin_newsletter_inboxes
				SET imap_server = ?, imap_port = ?, imap_user = ?, imap_pass = ?, imap_folder = ?
				WHERE id = ?");
			$upd->execute([$server, $port, $user, $pass, $folder, $existing['id']]);
		} else {
			$ins = $this->pdo->prepare("INSERT INTO ttrss_plugin_newsletter_inboxes
				(owner_uid, email_address, imap_server, imap_port, imap_user, imap_pass, imap_folder)
				VALUES (?, ?, ?, ?, ?, ?, ?)");
			$ins->execute([$_SESSION['uid'], $email, $server, $port, $user, $pass, $folder]);
		}

		echo __('Einstellungen gespeichert.');
	}

	/**
	 * IMAP-Verbindung testen (AJAX).
	 */
	function test_connection(): void {
		if (!function_exists('imap_open')) {
			print json_encode(['error' => __('php-imap-Erweiterung nicht verfügbar')]);
			return;
		}

		// Letzte Konfiguration des Benutzers laden
		$sth = $this->pdo->prepare("SELECT * FROM ttrss_plugin_newsletter_inboxes
			WHERE owner_uid = ? ORDER BY id DESC LIMIT 1");
		$sth->execute([$_SESSION['uid']]);

		$inbox = $sth->fetch();
		if (!$inbox) {
			print json_encode(['error' => __('Kein Postfach konfiguriert')]);
			return;
		}

		$mailbox = "{" . $inbox['imap_server'] . ":" . $inbox['imap_port'] .
			"/imap/ssl/novalidate-cert}" . $inbox['imap_folder'];

		$conn = @imap_open($mailbox, $inbox['imap_user'], $inbox['imap_pass']);
		if ($conn) {
			$info = @imap_status($conn, $mailbox, SA_MESSAGES);
			$count = $info ? $info->messages : 0;
			@imap_close($conn);
			print json_encode(['message' => sprintf(
				__('Verbindung erfolgreich. %d Nachrichten im Postfach.'), $count
			)]);
		} else {
			$error = imap_last_error();
			print json_encode(['error' => __('Verbindung fehlgeschlagen: ') . $error]);
		}
	}

	/**
	 * Sofortige Prüfung auslösen (AJAX).
	 */
	function check_now(): void {
		if (!function_exists('imap_open')) {
			print json_encode(['error' => __('php-imap-Erweiterung nicht verfügbar')]);
			return;
		}

		$sth = $this->pdo->prepare("SELECT * FROM ttrss_plugin_newsletter_inboxes
			WHERE owner_uid = ?");
		$sth->execute([$_SESSION['uid']]);

		$checked = 0;
		while ($row = $sth->fetch()) {
			$this->check_inbox($row);
			$checked++;
		}

		print json_encode(['message' => sprintf(
			__('%d Postfächer geprüft.'), $checked
		)]);
	}

	function api_version() {
		return 2;
	}
}
