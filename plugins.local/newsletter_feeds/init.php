<?php
class Newsletter_Feeds extends Plugin {

	/** @var PluginHost $host */
	private $host;

	private const RATE_LIMIT_MAX = 50;
	private const RATE_LIMIT_WINDOW = 300; // 5 Minuten
	private const POLL_INTERVAL = 300; // 5 Minuten

	function about() {
		return [2.0,
			"Newsletter per IMAP abrufen und als echte Feed-Einträge anlegen",
			"tt-ss",
			false];
	}

	function init($host) {
		$this->host = $host;

		// Hilfsklassen laden (Path-Traversal-Schutz: nur direkte Dateien im classes/ Verzeichnis)
		$class_dir = __DIR__ . '/classes';
		foreach (glob($class_dir . '/*.php') as $file) {
			// Sicherstellen, dass der aufgelöste Pfad im erwarteten Verzeichnis liegt
			$real_path = realpath($file);
			$real_class_dir = realpath($class_dir);
			if ($real_path && $real_class_dir && str_starts_with($real_path, $real_class_dir . DIRECTORY_SEPARATOR)) {
				require_once $real_path;
			}
		}

		$host->add_hook($host::HOOK_HOUSE_KEEPING, $this);
		$host->add_hook($host::HOOK_PREFS_TAB, $this);
		$host->add_hook($host::HOOK_SANITIZE, $this);
		$host->add_hook($host::HOOK_RENDER_ARTICLE_CDM, $this);
		$host->add_hook($host::HOOK_RENDER_ARTICLE, $this);
		$host->add_hook($host::HOOK_FETCH_FEED, $this);

		$m = new Db_Migrations();
		$m->initialize_for_plugin($this);
		$m->migrate();

		// Einmalige Datenmigration von v1 → v2
		$this->maybe_migrate_v1_data();
	}

	function get_css() {
		return file_get_contents(__DIR__ . "/newsletter_feeds.css");
	}

	// ─── House-Keeping: IMAP-Polling ───────────────────────────────────

	function hook_house_keeping(): void {
		// Owner-UID aus PluginHost (Daemon-Kontext) oder Session (Web-Kontext)
		$owner_uid = $this->host->get_owner_uid() ?: ($_SESSION['uid'] ?? 0);
		if (!$owner_uid) return;

		$sth = $this->pdo->prepare("SELECT * FROM ttrss_plugin_newsletter_inboxes
			WHERE owner_uid = ?
			  AND (last_checked IS NULL
			   OR last_checked < NOW() - MAKE_INTERVAL(secs => ?))");
		$sth->execute([$owner_uid, self::POLL_INTERVAL]);

		while ($row = $sth->fetch()) {
			$this->check_inbox($row);
		}

		// Alte Duplikat-Einträge aufräumen
		Newsletter_Article::cleanupProcessed();
	}

	/**
	 * Ein IMAP-Postfach prüfen und neue E-Mails verarbeiten.
	 * @param array<string, mixed> $inbox
	 */
	private function check_inbox(array $inbox): void {
		// Rate Limiting prüfen
		if ($this->is_rate_limited($inbox)) {
			return;
		}

		try {
			$client = Newsletter_ImapConnection::connect($inbox);
		} catch (\Exception $e) {
			Debug::log("Newsletter: IMAP-Verbindung fehlgeschlagen für {$inbox['email_address']}: " . $e->getMessage());
			$this->update_last_checked($inbox['id']);
			return;
		}

		try {
			$since = $inbox['last_checked']
				? date('Y-m-d', strtotime($inbox['last_checked']))
				: date('Y-m-d', strtotime('-1 day'));

			$messages = Newsletter_ImapConnection::fetchUnseenSince(
				$client,
				$inbox['imap_folder'] ?: 'INBOX',
				$since,
				self::RATE_LIMIT_MAX
			);

			$processed = 0;
			foreach ($messages as $message) {
				if ($processed >= self::RATE_LIMIT_MAX) break;

				try {
					$raw = $message->getRawBody();
					// Header + Body zusammensetzen für den Parser
					$raw_full = $message->getHeader()->raw . "\r\n\r\n" . $raw;

					$email_data = Newsletter_EmailParser::parse($raw_full);

					if (empty($email_data['sender_email'])) {
						Newsletter_ImapConnection::markSeen($message);
						continue;
					}

					$result = Newsletter_SenderManager::getOrCreateFeed($email_data, $inbox);

					if ($result) {
						Newsletter_Article::create(
							$email_data,
							$result['feed_id'],
							$result['subscription'],
							(int)$inbox['id']
						);
					}

					Newsletter_ImapConnection::markSeen($message);
					$processed++;

				} catch (\Exception $e) {
					Debug::log("Newsletter: E-Mail-Verarbeitung fehlgeschlagen: " . $e->getMessage());
				}
			}

			$this->update_rate_limit($inbox['id'], $processed);

		} catch (\Exception $e) {
			Debug::log("Newsletter: Abruf fehlgeschlagen für {$inbox['email_address']}: " . $e->getMessage());
		}

		try {
			$client->disconnect();
		} catch (\Exception $e) {
			// Ignorieren
		}

		$this->update_last_checked($inbox['id']);
	}

	// ─── Hook: Sanitizer — Newsletter-CSS erhalten ─────────────────────

	function hook_sanitize($doc, $site_url, $allowed_elements, $disallowed_attributes, $article_id) {
		if (!$article_id || !$this->is_newsletter_article($article_id)) {
			return [$doc, $allowed_elements, $disallowed_attributes];
		}

		// style-Attribut für Newsletter-Artikel erlauben
		$disallowed_attributes = array_values(array_diff($disallowed_attributes, ['style']));

		// Gefährliche CSS-Properties entfernen (Defense-in-Depth)
		$xpath = new DOMXpath($doc);
		$styled = $xpath->query('//*[@style]');

		if ($styled) {
			foreach ($styled as $el) {
				$style = $el->getAttribute('style');
				$style = self::sanitize_css($style);
				if ($style) {
					$el->setAttribute('style', $style);
				} else {
					$el->removeAttribute('style');
				}
			}
		}

		// <style>-Elemente komplett entfernen (nur inline styles erlauben)
		$style_elements = $xpath->query('//style');
		if ($style_elements) {
			foreach ($style_elements as $style_el) {
				$style_el->parentNode->removeChild($style_el);
			}
		}

		return [$doc, $allowed_elements, $disallowed_attributes];
	}

	// ─── Hook: Artikel-Rendering — Absender-Banner ─────────────────────

	function hook_render_article_cdm($article) {
		return $this->inject_sender_banner($article);
	}

	function hook_render_article($article) {
		return $this->inject_sender_banner($article);
	}

	private function inject_sender_banner(array $article): array {
		$feed_id = $article['feed_id'] ?? null;
		if (!$feed_id) return $article;

		$sub = Newsletter_SenderManager::getByFeedId((int)$feed_id, (int)($_SESSION['uid'] ?? 0));
		if (!$sub) return $article;

		$name = htmlspecialchars($sub['sender_name'] ?: $sub['sender_email']);
		$email = htmlspecialchars($sub['sender_email']);
		$domain = htmlspecialchars($sub['sender_domain']);

		$banner = '<div class="newsletter-sender-info">';
		$banner .= '<i class="material-icons">email</i>';
		$banner .= '<span class="nsi-name">' . $name . '</span>';
		if ($sub['sender_name']) {
			$banner .= '<span class="nsi-email">&lt;' . $email . '&gt;</span>';
		}
		$banner .= '<span class="nsi-domain">' . $domain . '</span>';

		if (!empty($sub['list_unsubscribe'])) {
			$unsub_url = htmlspecialchars($sub['list_unsubscribe']);
			$banner .= '<a class="nsi-unsubscribe" href="' . $unsub_url . '" target="_blank" rel="noopener">';
			$banner .= '<i class="material-icons">unsubscribe</i> ' . __('Abmelden');
			$banner .= '</a>';
		}

		$banner .= '</div>';

		$article['content'] = $banner . ($article['content'] ?? '');
		return $article;
	}

	// ─── Hook: Feed-Fetch abfangen für newsletter:// URLs ──────────────

	function hook_fetch_feed($feed_data, $fetch_url, $owner_uid, $feed, $last_article_timestamp = 0, $auth_login = '', $auth_pass = '') {
		if (Newsletter_SenderManager::isNewsletterFeed($fetch_url)) {
			return ''; // Leeres Ergebnis, RSS-Updater soll nichts tun
		}
		return $feed_data;
	}

	// ─── Einstellungen-UI ──────────────────────────────────────────────

	function hook_prefs_tab($args) {
		if ($args != "prefFeeds") return;

		?>
		<div dojoType="dijit.layout.AccordionPane"
			title="<i class='material-icons'>email</i> <?= __('Newsletter-Feeds') ?>">

			<?php $this->render_inbox_section(); ?>
			<?php $this->render_subscriptions_section(); ?>
			<?php $this->render_blocklist_section(); ?>

		</div>
		<?php
	}

	private function render_inbox_section(): void {
		$sth = $this->pdo->prepare("SELECT ni.*, fc.title AS cat_title
			FROM ttrss_plugin_newsletter_inboxes ni
			LEFT JOIN ttrss_feed_categories fc ON fc.id = ni.default_cat_id
			WHERE ni.owner_uid = ? ORDER BY ni.created_at DESC");
		$sth->execute([$_SESSION['uid']]);

		// Kategorien laden
		$cat_sth = $this->pdo->prepare("SELECT id, title FROM ttrss_feed_categories
			WHERE owner_uid = ? ORDER BY title");
		$cat_sth->execute([$_SESSION['uid']]);
		$categories = $cat_sth->fetchAll();

		?>
		<h3><?= __('IMAP-Postfach konfigurieren') ?></h3>

		<form dojoType="dijit.form.Form" id="newsletter_inbox_form">
			<?= \Controls\pluginhandler_tags($this, "save_inbox") ?>

			<script type="dojo/method" event="onSubmit" args="evt">
				evt.preventDefault();
				if (this.validate()) {
					Notify.progress('Speichere...', true);
					xhr.post("backend.php", this.getValues(), (reply) => {
						Notify.info(reply);
					});
				}
			</script>

			<fieldset>
				<label><?= __('E-Mail-Adresse:') ?></label>
				<input dojoType="dijit.form.TextBox" name="email_address"
					style="width: 300px" placeholder="newsletter@jpigla.de">
			</fieldset>

			<fieldset>
				<label><?= __('IMAP-Server:') ?></label>
				<input dojoType="dijit.form.TextBox" name="imap_server"
					style="width: 300px" placeholder="imap.example.com">
			</fieldset>

			<fieldset>
				<label><?= __('IMAP-Port:') ?></label>
				<input dojoType="dijit.form.NumberSpinner" name="imap_port"
					value="993" constraints="{min:1,max:65535}">
			</fieldset>

			<fieldset>
				<label><?= __('IMAP-Benutzer:') ?></label>
				<input dojoType="dijit.form.TextBox" name="imap_user"
					style="width: 300px">
			</fieldset>

			<fieldset>
				<label><?= __('IMAP-Passwort:') ?></label>
				<input dojoType="dijit.form.TextBox" name="imap_pass"
					type="password" style="width: 300px">
			</fieldset>

			<fieldset>
				<label><?= __('IMAP-Ordner:') ?></label>
				<input dojoType="dijit.form.TextBox" name="imap_folder"
					value="INBOX" style="width: 200px">
			</fieldset>

			<fieldset>
				<label><?= __('Standard-Ordner für neue Newsletter:') ?></label>
				<select dojoType="dijit.form.Select" name="default_cat_id">
					<option value=""><?= __('Keine Kategorie') ?></option>
					<?php foreach ($categories as $cat) { ?>
					<option value="<?= (int)$cat['id'] ?>"><?= htmlspecialchars($cat['title']) ?></option>
					<?php } ?>
				</select>
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

		<h4><?= __('Konfigurierte Postfächer') ?></h4>
		<table class="nf-table" width="100%">
			<tr class="title">
				<td><?= __('E-Mail') ?></td>
				<td><?= __('Server') ?></td>
				<td><?= __('Ordner') ?></td>
				<td><?= __('Standard-Kategorie') ?></td>
				<td><?= __('Zuletzt geprüft') ?></td>
				<td><?= __('Aktionen') ?></td>
			</tr>
			<?php while ($row = $sth->fetch()) { ?>
			<tr>
				<td><?= htmlspecialchars($row['email_address']) ?></td>
				<td><?= htmlspecialchars($row['imap_server']) ?>:<?= (int)$row['imap_port'] ?></td>
				<td><?= htmlspecialchars($row['imap_folder']) ?></td>
				<td><?= htmlspecialchars($row['cat_title'] ?? __('Keine')) ?></td>
				<td><?= htmlspecialchars($row['last_checked'] ?? __('Noch nicht')) ?></td>
				<td>
					<button dojoType="dijit.form.Button" type="button" class="alt-danger"
						onclick="if(confirm('<?= __('Postfach wirklich löschen?') ?>')) xhr.json('backend.php', {op: 'pluginhandler', plugin: 'newsletter_feeds', method: 'delete_inbox', id: <?= (int)$row['id'] ?>}, () => { window.location.reload(); })">
						<i class="material-icons">delete</i>
					</button>
				</td>
			</tr>
			<?php } ?>
		</table>
		<?php
	}

	private function render_subscriptions_section(): void {
		$subs = Newsletter_SenderManager::getAll($_SESSION['uid']);

		$cat_sth = $this->pdo->prepare("SELECT id, title FROM ttrss_feed_categories
			WHERE owner_uid = ? ORDER BY title");
		$cat_sth->execute([$_SESSION['uid']]);
		$categories = $cat_sth->fetchAll();

		// Labels laden
		$labels = Labels::get_all($_SESSION['uid']);

		?>
		<h3><?= __('Newsletter-Abonnements') ?></h3>

		<?php if (empty($subs)) { ?>
			<p class="text-muted"><?= __('Noch keine Newsletter empfangen. Abonnements erscheinen automatisch, sobald E-Mails eintreffen.') ?></p>
		<?php } else { ?>
		<table class="nf-table nf-subs-table" width="100%">
			<tr class="title">
				<td><?= __('Absender') ?></td>
				<td><?= __('E-Mail') ?></td>
				<td><?= __('Ordner') ?></td>
				<td><?= __('Auto-Labels') ?></td>
				<td><?= __('Nachrichten') ?></td>
				<td><?= __('Letzte E-Mail') ?></td>
				<td><?= __('Aktionen') ?></td>
			</tr>
			<?php foreach ($subs as $sub) { ?>
			<tr data-sub-id="<?= (int)$sub['id'] ?>">
				<td>
					<strong><?= htmlspecialchars($sub['sender_name'] ?: $sub['sender_email']) ?></strong>
					<br><small class="text-muted"><?= htmlspecialchars($sub['sender_domain']) ?></small>
				</td>
				<td><?= htmlspecialchars($sub['sender_email']) ?></td>
				<td>
					<select dojoType="dijit.form.Select"
						onchange="xhr.json('backend.php', {op: 'pluginhandler', plugin: 'newsletter_feeds', method: 'update_sub_category', sub_id: <?= (int)$sub['id'] ?>, cat_id: this.value}, (r) => { Notify.info(r.message); })">
						<option value="" <?= empty($sub['cat_id']) ? 'selected' : '' ?>><?= __('Keine') ?></option>
						<?php foreach ($categories as $cat) { ?>
						<option value="<?= (int)$cat['id'] ?>" <?= ((int)($sub['cat_id'] ?? 0) === (int)$cat['id']) ? 'selected' : '' ?>>
							<?= htmlspecialchars($cat['title']) ?>
						</option>
						<?php } ?>
					</select>
				</td>
				<td>
					<input dojoType="dijit.form.TextBox" style="width: 150px"
						value="<?= htmlspecialchars($sub['auto_labels']) ?>"
						placeholder="Label1, Label2"
						onchange="xhr.json('backend.php', {op: 'pluginhandler', plugin: 'newsletter_feeds', method: 'update_sub_labels', sub_id: <?= (int)$sub['id'] ?>, labels: this.value}, (r) => { Notify.info(r.message); })">
				</td>
				<td><?= (int)$sub['message_count'] ?></td>
				<td><?= $sub['last_message_at'] ? htmlspecialchars(TimeHelper::make_local_datetime($sub['last_message_at'], false)) : __('—') ?></td>
				<td>
					<?php if (!empty($sub['list_unsubscribe'])) { ?>
					<a href="<?= htmlspecialchars($sub['list_unsubscribe']) ?>" target="_blank" rel="noopener"
						title="<?= __('Newsletter abmelden') ?>">
						<i class="material-icons">unsubscribe</i>
					</a>
					<?php } ?>
					<button dojoType="dijit.form.Button" type="button" class="alt-danger"
						title="<?= __('Absender blockieren') ?>"
						onclick="if(confirm('<?= __('Absender wirklich blockieren?') ?>')) xhr.json('backend.php', {op: 'pluginhandler', plugin: 'newsletter_feeds', method: 'block_subscription', sub_id: <?= (int)$sub['id'] ?>}, () => { window.location.reload(); })">
						<i class="material-icons">block</i>
					</button>
				</td>
			</tr>
			<?php } ?>
		</table>
		<?php } ?>
		<?php
	}

	private function render_blocklist_section(): void {
		$patterns = Newsletter_BlocklistManager::get_all($_SESSION['uid']);

		?>
		<h3><?= __('Blocklist / Allowlist') ?></h3>

		<form dojoType="dijit.form.Form" id="newsletter_blocklist_form">
			<?= \Controls\pluginhandler_tags($this, "add_blocklist_pattern") ?>

			<script type="dojo/method" event="onSubmit" args="evt">
				evt.preventDefault();
				if (this.validate()) {
					xhr.post("backend.php", this.getValues(), (reply) => {
						Notify.info(reply);
						window.location.reload();
					});
				}
			</script>

			<fieldset>
				<label><?= __('Pattern:') ?></label>
				<input dojoType="dijit.form.TextBox" name="pattern"
					style="width: 250px" placeholder="*@spam-domain.com"
					required="true">

				<select dojoType="dijit.form.Select" name="is_block">
					<option value="1" selected><?= __('Blockieren') ?></option>
					<option value="0"><?= __('Erlauben') ?></option>
				</select>

				<?= \Controls\submit_tag(__("Hinzufügen")) ?>
			</fieldset>
		</form>

		<?php if (!empty($patterns)) { ?>
		<table class="nf-table" width="100%">
			<tr class="title">
				<td><?= __('Pattern') ?></td>
				<td><?= __('Typ') ?></td>
				<td><?= __('Erstellt') ?></td>
				<td><?= __('Aktionen') ?></td>
			</tr>
			<?php foreach ($patterns as $p) { ?>
			<tr>
				<td><code><?= htmlspecialchars($p['pattern']) ?></code></td>
				<td><?= $p['is_block'] ? __('Blockieren') : __('Erlauben') ?></td>
				<td><?= htmlspecialchars(TimeHelper::make_local_datetime($p['created_at'], false)) ?></td>
				<td>
					<button dojoType="dijit.form.Button" type="button" class="alt-danger"
						onclick="xhr.json('backend.php', {op: 'pluginhandler', plugin: 'newsletter_feeds', method: 'remove_blocklist_pattern', pattern_id: <?= (int)$p['id'] ?>}, () => { window.location.reload(); })">
						<i class="material-icons">delete</i>
					</button>
				</td>
			</tr>
			<?php } ?>
		</table>
		<?php } else { ?>
			<p class="text-muted"><?= __('Keine Einträge. Patterns wie *@spam.com blockieren ganze Domains.') ?></p>
		<?php } ?>
		<?php
	}

	// ─── AJAX-Handler ──────────────────────────────────────────────────

	function save_inbox(): void {
		$email = clean($_REQUEST['email_address'] ?? '');
		$server = clean($_REQUEST['imap_server'] ?? '');
		$port = (int)clean($_REQUEST['imap_port'] ?? 993);
		$user = clean($_REQUEST['imap_user'] ?? '');
		$pass = $_REQUEST['imap_pass'] ?? '';
		$folder = clean($_REQUEST['imap_folder'] ?? 'INBOX');
		$default_cat_id = !empty($_REQUEST['default_cat_id']) ? (int)$_REQUEST['default_cat_id'] : null;

		if (empty($email) || empty($server)) {
			echo __('E-Mail-Adresse und Server sind erforderlich.');
			return;
		}

		// E-Mail-Adresse validieren
		if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
			echo __('Ungültige E-Mail-Adresse.');
			return;
		}

		// IMAP-Server validieren (SSRF-Schutz)
		if (!Newsletter_ImapConnection::is_valid_imap_host($server)) {
			echo __('Ungültiger IMAP-Server. Lokale oder private Adressen sind nicht erlaubt.');
			return;
		}

		// Port validieren
		if ($port < 1 || $port > 65535) {
			echo __('Ungültiger Port.');
			return;
		}

		// IMAP-Ordner validieren (nur erlaubte Zeichen)
		if (!preg_match('/^[a-zA-Z0-9_.\/\-\[\]]+$/', $folder)) {
			echo __('Ungültiger IMAP-Ordner.');
			return;
		}

		// Länge der Eingaben begrenzen
		if (mb_strlen($email) > 250 || mb_strlen($server) > 250 || mb_strlen($user) > 250 || mb_strlen($folder) > 100) {
			echo __('Eingabe zu lang.');
			return;
		}

		// Passwort verschlüsseln
		$pass_encrypted = null;
		if (!empty($pass)) {
			try {
				$pass_encrypted = Newsletter_ImapConnection::encrypt_password($pass);
			} catch (\Exception $e) {
				Debug::log("Newsletter: Passwort-Verschlüsselung fehlgeschlagen: " . $e->getMessage());
				echo __('Fehler bei der Passwort-Verschlüsselung.');
				return;
			}
		}

		// Prüfen ob bereits vorhanden
		$sth = $this->pdo->prepare("SELECT id FROM ttrss_plugin_newsletter_inboxes
			WHERE email_address = ? AND owner_uid = ?");
		$sth->execute([$email, $_SESSION['uid']]);

		if ($existing = $sth->fetch()) {
			$params = [$server, $port, $user, $folder, $default_cat_id];
			$pass_sql = '';
			if ($pass_encrypted) {
				$pass_sql = ', imap_pass_encrypted = ?, imap_pass = ?';
				$params[] = $pass_encrypted;
				$params[] = ''; // Klartext-Passwort leeren
			}
			$params[] = $existing['id'];

			$upd = $this->pdo->prepare("UPDATE ttrss_plugin_newsletter_inboxes
				SET imap_server = ?, imap_port = ?, imap_user = ?, imap_folder = ?,
				    default_cat_id = ? {$pass_sql}
				WHERE id = ?");
			$upd->execute($params);
		} else {
			$sth = $this->pdo->prepare("INSERT INTO ttrss_plugin_newsletter_inboxes
				(owner_uid, email_address, imap_server, imap_port, imap_user, imap_pass, imap_pass_encrypted, imap_folder, default_cat_id)
				VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
			$sth->execute([
				$_SESSION['uid'], $email, $server, $port, $user,
				'', // Klartext-Passwort leer
				$pass_encrypted ?? '',
				$folder, $default_cat_id
			]);
		}

		echo __('Einstellungen gespeichert.');
	}

	function delete_inbox(): void {
		$id = (int)($_REQUEST['id'] ?? 0);
		if (!$id) return;

		$sth = $this->pdo->prepare("DELETE FROM ttrss_plugin_newsletter_inboxes
			WHERE id = ? AND owner_uid = ?");
		$sth->execute([$id, $_SESSION['uid']]);

		print json_encode(['message' => __('Postfach gelöscht.')]);
	}

	function test_connection(): void {
		$sth = $this->pdo->prepare("SELECT * FROM ttrss_plugin_newsletter_inboxes
			WHERE owner_uid = ? ORDER BY id DESC LIMIT 1");
		$sth->execute([$_SESSION['uid']]);

		$inbox = $sth->fetch();
		if (!$inbox) {
			print json_encode(['error' => __('Kein Postfach konfiguriert')]);
			return;
		}

		$result = Newsletter_ImapConnection::testConnection($inbox);
		print json_encode($result['success']
			? ['message' => $result['message']]
			: ['error' => $result['message']]
		);
	}

	function check_now(): void {
		$sth = $this->pdo->prepare("SELECT * FROM ttrss_plugin_newsletter_inboxes
			WHERE owner_uid = ?");
		$sth->execute([$_SESSION['uid']]);

		$checked = 0;
		$articles = 0;
		while ($row = $sth->fetch()) {
			$this->check_inbox($row);
			$checked++;
		}

		print json_encode(['message' => sprintf(
			__('%d Postfächer geprüft.'), $checked
		)]);
	}

	function update_sub_category(): void {
		$sub_id = (int)($_REQUEST['sub_id'] ?? 0);
		$cat_id = !empty($_REQUEST['cat_id']) ? (int)$_REQUEST['cat_id'] : null;

		Newsletter_SenderManager::updateCategory($sub_id, $cat_id, $_SESSION['uid']);
		print json_encode(['message' => __('Ordner aktualisiert.')]);
	}

	function update_sub_labels(): void {
		$sub_id = (int)($_REQUEST['sub_id'] ?? 0);
		$labels = clean($_REQUEST['labels'] ?? '');

		Newsletter_SenderManager::updateAutoLabels($sub_id, $labels, $_SESSION['uid']);
		print json_encode(['message' => __('Labels aktualisiert.')]);
	}

	function block_subscription(): void {
		$sub_id = (int)($_REQUEST['sub_id'] ?? 0);

		Newsletter_SenderManager::blockSubscription($sub_id, $_SESSION['uid']);
		print json_encode(['message' => __('Absender blockiert.')]);
	}

	function add_blocklist_pattern(): void {
		$pattern = clean($_REQUEST['pattern'] ?? '');
		$is_block = (bool)($_REQUEST['is_block'] ?? 1);

		if (empty($pattern)) {
			echo __('Pattern ist erforderlich.');
			return;
		}

		if (!Newsletter_BlocklistManager::is_valid_pattern($pattern)) {
			echo __('Ungültiges Pattern. Erlaubt sind z.B.: *@domain.com, user@domain.com, *@*.ru');
			return;
		}

		Newsletter_BlocklistManager::add_pattern($pattern, $is_block, $_SESSION['uid']);
		echo __('Pattern hinzugefügt.');
	}

	function remove_blocklist_pattern(): void {
		$id = (int)($_REQUEST['pattern_id'] ?? 0);

		Newsletter_BlocklistManager::remove_pattern($id, $_SESSION['uid']);
		print json_encode(['message' => __('Pattern entfernt.')]);
	}

	// ─── Hilfsmethoden ─────────────────────────────────────────────────

	/**
	 * Prüft ob ein Artikel zu einem Newsletter-Feed gehört.
	 * Prüft auch owner_uid um Cross-User-Zugriff zu verhindern.
	 */
	private function is_newsletter_article(int $article_id): bool {
		$owner_uid = $_SESSION['uid'] ?? 0;
		if (!$owner_uid) return false;

		$sth = $this->pdo->prepare("SELECT f.feed_url FROM ttrss_user_entries ue
			JOIN ttrss_feeds f ON f.id = ue.feed_id
			WHERE ue.ref_id = ? AND ue.owner_uid = ? AND f.feed_url LIKE 'newsletter://%'
			LIMIT 1");
		$sth->execute([$article_id, $owner_uid]);
		return (bool)$sth->fetch();
	}

	/**
	 * CSS-Properties bereinigen — Whitelist-basiert für sichere Properties.
	 * Entfernt: position, expression(), javascript:, behavior, binding, @import,
	 * pointer-events, content, cursor, visibility, opacity-basierte Overlays, url() mit externen Quellen.
	 */
	private static function sanitize_css(string $style): string {
		// Null-Bytes und unsichtbare Zeichen entfernen
		$style = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $style);

		// CSS-Kommentare entfernen (können zum Umgehen von Filtern genutzt werden)
		$style = preg_replace('/\/\*.*?\*\//s', '', $style);

		// Backslash-Escaping entfernen (CSS-Obfuskation)
		$style = preg_replace('/\\\\([0-9a-fA-F]{1,6})\s?/', '', $style);
		$style = str_replace('\\', '', $style);

		// Gefährliche Patterns entfernen
		$dangerous_patterns = [
			// Scripting/dynamisches Verhalten
			'/expression\s*\(/i',
			'/javascript\s*:/i',
			'/vbscript\s*:/i',
			'/behavior\s*:/i',
			'/(?:-moz-|-webkit-)?binding\s*:/i',
			'/@import\b/i',
			'/@charset\b/i',
			'/@font-face\b/i',

			// Layout-Manipulation (Clickjacking, Overlay-Angriffe)
			'/position\s*:\s*(?:fixed|absolute|sticky)/i',
			'/pointer-events\s*:/i',
			'/z-index\s*:\s*[0-9]{4,}/i', // Sehr hohe z-index Werte

			// Inhaltsinjektion
			'/content\s*:/i',

			// Sichtbarkeits-Manipulation für Phishing
			'/visibility\s*:\s*hidden/i',
			'/display\s*:\s*none/i',
			'/opacity\s*:\s*0(?:\.\d+)?(?:\s*;|\s*$)/i',
			'/clip\s*:/i',
			'/clip-path\s*:/i',

			// Cursor-Manipulation
			'/cursor\s*:\s*(?!default|pointer|text|auto)[^;]*/i',
		];

		foreach ($dangerous_patterns as $pattern) {
			$style = preg_replace($pattern, '', $style);
		}

		// url()-Referenzen: nur data: Image-URIs erlauben, alles andere entfernen
		$style = preg_replace(
			'/url\s*\(\s*["\']?(?!data:image\/(?:png|jpe?g|gif|webp|svg\+xml);)[^)]*\)/i',
			'none',
			$style
		);

		// Leere Deklarationen und doppelte Semikolons bereinigen
		$style = preg_replace('/;\s*;/', ';', $style);
		$style = preg_replace('/:\s*;/', '', $style);
		$style = trim($style, '; ');

		return $style;
	}

	private function update_last_checked(int $inbox_id): void {
		$sth = $this->pdo->prepare("UPDATE ttrss_plugin_newsletter_inboxes
			SET last_checked = NOW() WHERE id = ?");
		$sth->execute([$inbox_id]);
	}

	private function is_rate_limited(array $inbox): bool {
		if (empty($inbox['rate_limit_reset'])) return false;

		$reset_time = strtotime($inbox['rate_limit_reset']);
		if (time() > $reset_time + self::RATE_LIMIT_WINDOW) {
			// Fenster abgelaufen, Counter zurücksetzen
			$sth = $this->pdo->prepare("UPDATE ttrss_plugin_newsletter_inboxes
				SET rate_limit_count = 0, rate_limit_reset = NULL WHERE id = ?");
			$sth->execute([$inbox['id']]);
			return false;
		}

		return (int)$inbox['rate_limit_count'] >= self::RATE_LIMIT_MAX;
	}

	private function update_rate_limit(int $inbox_id, int $processed): void {
		if ($processed <= 0) return;

		$sth = $this->pdo->prepare("UPDATE ttrss_plugin_newsletter_inboxes
			SET rate_limit_count = rate_limit_count + ?,
			    rate_limit_reset = COALESCE(rate_limit_reset, NOW())
			WHERE id = ?");
		$sth->execute([$processed, $inbox_id]);
	}

	// ─── Datenmigration v1 → v2 ───────────────────────────────────────

	private function maybe_migrate_v1_data(): void {
		if ($this->host->get($this, "v2_migrated")) return;

		try {
			// 1. Passwörter verschlüsseln (Klartext → verschlüsselt)
			$sth = $this->pdo->prepare("SELECT id, imap_pass FROM ttrss_plugin_newsletter_inboxes
				WHERE imap_pass != '' AND (imap_pass_encrypted IS NULL OR imap_pass_encrypted = '')");
			$sth->execute();

			while ($row = $sth->fetch()) {
				try {
					$encrypted = Newsletter_ImapConnection::encrypt_password($row['imap_pass']);
					$upd = $this->pdo->prepare("UPDATE ttrss_plugin_newsletter_inboxes
						SET imap_pass_encrypted = ?, imap_pass = '' WHERE id = ?");
					$upd->execute([$encrypted, $row['id']]);
				} catch (\Exception $e) {
					Debug::log("Newsletter: Migration - Passwort-Verschlüsselung fehlgeschlagen für Inbox {$row['id']}: " . $e->getMessage());
				}
			}

			// 1b. Legacy-serialize-Passwörter ins sichere JSON-Format migrieren
			$sth = $this->pdo->prepare("SELECT id, imap_pass_encrypted FROM ttrss_plugin_newsletter_inboxes
				WHERE imap_pass_encrypted IS NOT NULL AND imap_pass_encrypted != ''");
			$sth->execute();

			while ($row = $sth->fetch()) {
				try {
					$legacy = Newsletter_ImapConnection::decode_legacy_encrypted($row['imap_pass_encrypted']);
					if ($legacy) {
						// Entschlüsseln und im neuen JSON-Format neu verschlüsseln
						$password = Crypt::decrypt_string($legacy);
						$new_encrypted = Newsletter_ImapConnection::encrypt_password($password);
						$upd = $this->pdo->prepare("UPDATE ttrss_plugin_newsletter_inboxes
							SET imap_pass_encrypted = ? WHERE id = ?");
						$upd->execute([$new_encrypted, $row['id']]);
					}
				} catch (\Exception $e) {
					Debug::log("Newsletter: Migration - Re-Verschlüsselung fehlgeschlagen für Inbox {$row['id']}: " . $e->getMessage());
				}
			}

			// 2. Bestehende Published Articles migrieren (aus v1)
			// Identifizierbar: feed_id IS NULL, published = true, link LIKE 'mailto:%'
			$sth = $this->pdo->prepare("SELECT ue.ref_id, ue.owner_uid, e.link, e.title, e.author
				FROM ttrss_user_entries ue
				JOIN ttrss_entries e ON e.id = ue.ref_id
				WHERE ue.feed_id IS NULL AND ue.published = true AND e.link LIKE 'mailto:%'");
			$sth->execute();

			while ($row = $sth->fetch()) {
				// Absender-E-Mail aus Link extrahieren
				$email = str_replace('mailto:', '', $row['link']);
				if (empty($email) || !str_contains($email, '@')) continue;

				$domain = substr($email, strpos($email, '@') + 1);

				// Inbox des Benutzers finden
				$inbox_sth = $this->pdo->prepare("SELECT id, default_cat_id FROM ttrss_plugin_newsletter_inboxes
					WHERE owner_uid = ? LIMIT 1");
				$inbox_sth->execute([$row['owner_uid']]);
				$inbox = $inbox_sth->fetch();
				if (!$inbox) continue;

				// Feed erstellen oder finden
				$email_data = [
					'sender_email' => $email,
					'sender_name' => $row['author'] ?? '',
					'sender_domain' => $domain,
					'list_unsubscribe_url' => null,
				];

				$result = Newsletter_SenderManager::getOrCreateFeed($email_data, array_merge($inbox, ['owner_uid' => $row['owner_uid']]));
				if (!$result) continue;

				// Artikel-Zuordnung aktualisieren
				$upd = $this->pdo->prepare("UPDATE ttrss_user_entries
					SET feed_id = ?, published = false
					WHERE ref_id = ? AND owner_uid = ?");
				$upd->execute([$result['feed_id'], $row['ref_id'], $row['owner_uid']]);
			}

			$this->host->set($this, "v2_migrated", true);
			Debug::log("Newsletter: v1 → v2 Migration abgeschlossen.");

		} catch (\Exception $e) {
			Debug::log("Newsletter: v1 → v2 Migration fehlgeschlagen: " . $e->getMessage());
		}
	}

	function api_version() {
		return 2;
	}
}
