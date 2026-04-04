<?php
class Track_Changes extends Plugin {

	/** @var PluginHost $host */
	private $host;

	function about() {
		return [1.0,
			"Überwacht Webseiten auf Änderungen und erzeugt Feed-Einträge bei Änderungen",
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
		return file_get_contents(__DIR__ . "/track_changes.css");
	}

	/**
	 * Periodisch alle überwachten URLs prüfen.
	 */
	function hook_house_keeping(): void {
		$sth = $this->pdo->prepare("SELECT * FROM ttrss_plugin_track_changes
			WHERE last_checked IS NULL
			   OR last_checked < NOW() - (check_interval || ' seconds')::INTERVAL");
		$sth->execute();

		while ($row = $sth->fetch()) {
			$this->check_url($row);
		}
	}

	/**
	 * Prüft eine einzelne URL auf Änderungen.
	 * @param array<string, mixed> $row Datenbankzeile
	 */
	private function check_url(array $row): void {
		$url = $row['url'];
		$id = $row['id'];
		$owner_uid = $row['owner_uid'];

		$result = UrlHelper::fetch(['url' => $url, 'timeout' => 15]);

		if ($result === false) {
			// Zeitstempel trotzdem aktualisieren, damit wir nicht ständig wiederholen
			$upd = $this->pdo->prepare("UPDATE ttrss_plugin_track_changes
				SET last_checked = NOW() WHERE id = ?");
			$upd->execute([$id]);
			return;
		}

		$content = $result;

		// CSS-Selektor anwenden, falls vorhanden
		if (!empty($row['css_selector'])) {
			$content = $this->extract_by_selector($content, $row['css_selector']);
		}

		$hash = md5($content);

		// Zeitstempel aktualisieren
		$upd = $this->pdo->prepare("UPDATE ttrss_plugin_track_changes
			SET last_checked = NOW(), last_hash = ?, last_content = ?
			WHERE id = ?");
		$upd->execute([$hash, mb_substr($content, 0, 50000), $id]);

		// Wenn sich der Hash geändert hat und es einen vorherigen Hash gab
		if (!empty($row['last_hash']) && $hash !== $row['last_hash']) {
			$title = !empty($row['title']) ? $row['title'] : parse_url($url, PHP_URL_HOST);
			$diff_html = "<p><strong>Neue Version erkannt</strong> - " . date('d.m.Y H:i') . "</p>"
				. "<p>Die überwachte Seite hat sich geändert:</p>"
				. "<blockquote>" . mb_substr(strip_tags($content), 0, 2000) . "</blockquote>"
				. "<p><a href=\"" . htmlspecialchars($url) . "\">Seite öffnen</a></p>";

			Article::_create_published_article(
				$title . " - Änderung erkannt",
				$url,
				$diff_html,
				'',
				$owner_uid
			);
		}
	}

	/**
	 * Extrahiert Inhalt anhand eines CSS-Selektors via DOMXPath.
	 * @param string $html
	 * @param string $selector Einfacher CSS-Selektor (Tag, .class, #id)
	 * @return string
	 */
	private function extract_by_selector(string $html, string $selector): string {
		$doc = new DOMDocument();
		$prev = libxml_use_internal_errors(true);
		$doc->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
		libxml_use_internal_errors($prev);

		$xpath = new DOMXPath($doc);

		// Einfache CSS-Selektor-zu-XPath-Konvertierung
		$xp = $selector;
		if (str_starts_with($selector, '#')) {
			$xp = "//*[@id='" . substr($selector, 1) . "']";
		} elseif (str_starts_with($selector, '.')) {
			$class = substr($selector, 1);
			$xp = "//*[contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')]";
		} else {
			$xp = "//" . $selector;
		}

		$nodes = $xpath->query($xp);
		if ($nodes === false || $nodes->length === 0) {
			return $html;
		}

		$result = '';
		foreach ($nodes as $node) {
			$result .= $doc->saveHTML($node);
		}

		return $result;
	}

	function hook_prefs_tab($args) {
		if ($args != "prefFeeds") return;

		$owner_uid = $_SESSION['uid'];

		$sth = $this->pdo->prepare("SELECT * FROM ttrss_plugin_track_changes
			WHERE owner_uid = ? ORDER BY created_at DESC");
		$sth->execute([$owner_uid]);

		?>
		<div dojoType="dijit.layout.AccordionPane"
			title="<i class='material-icons'>track_changes</i> <?= __('Seiten-Überwachung') ?>">

			<h3><?= __('Überwachte Seiten') ?></h3>

			<table class="track-changes-table">
				<thead>
					<tr>
						<th><?= __('Titel') ?></th>
						<th><?= __('URL') ?></th>
						<th><?= __('Selektor') ?></th>
						<th><?= __('Intervall') ?></th>
						<th><?= __('Zuletzt geprüft') ?></th>
						<th><?= __('Status') ?></th>
						<th><?= __('Aktionen') ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					$has_rows = false;
					while ($row = $sth->fetch()) {
						$has_rows = true;
						$status_class = empty($row['last_hash']) ? 'tc-status-new' : 'tc-status-ok';
						$status_text = empty($row['last_hash']) ? __('Neu') : __('OK');
						$last_checked = $row['last_checked']
							? TimeHelper::smart_date_time(strtotime($row['last_checked']))
							: __('Noch nicht geprüft');
						$interval_hours = intval($row['check_interval']) / 3600;
					?>
					<tr>
						<td><?= htmlspecialchars($row['title']) ?></td>
						<td class="url-cell">
							<a href="<?= htmlspecialchars($row['url']) ?>" target="_blank" rel="noopener noreferrer">
								<?= htmlspecialchars(mb_substr($row['url'], 0, 50)) ?>
							</a>
						</td>
						<td><code><?= htmlspecialchars($row['css_selector']) ?></code></td>
						<td><?= $interval_hours ?>h</td>
						<td><?= $last_checked ?></td>
						<td><span class="<?= $status_class ?>"><?= $status_text ?></span></td>
						<td class="actions">
							<form dojoType="dijit.form.Form" style="display: inline;">
								<?= \Controls\pluginhandler_tags($this, "check_now") ?>
								<input type="hidden" name="track_id" value="<?= $row['id'] ?>">
								<button dojoType="dijit.form.Button" type="submit" title="<?= __('Jetzt prüfen') ?>">
									<i class="material-icons">refresh</i>
								</button>
								<script type="dojo/method" event="onSubmit" args="evt">
									evt.preventDefault();
									xhr.post("backend.php", this.getValues(), (reply) => {
										Notify.info(reply);
										window.setTimeout(() => { location.reload(); }, 1000);
									});
								</script>
							</form>
							<form dojoType="dijit.form.Form" style="display: inline;">
								<?= \Controls\pluginhandler_tags($this, "remove_tracked") ?>
								<input type="hidden" name="track_id" value="<?= $row['id'] ?>">
								<button dojoType="dijit.form.Button" type="submit" class="alt-danger" title="<?= __('Entfernen') ?>">
									<i class="material-icons">delete</i>
								</button>
								<script type="dojo/method" event="onSubmit" args="evt">
									evt.preventDefault();
									if (confirm("<?= __('Wirklich entfernen?') ?>")) {
										xhr.post("backend.php", this.getValues(), (reply) => {
											Notify.info(reply);
											window.setTimeout(() => { location.reload(); }, 1000);
										});
									}
								</script>
							</form>
						</td>
					</tr>
					<?php } ?>
					<?php if (!$has_rows) { ?>
					<tr>
						<td colspan="7" style="text-align: center; padding: 16px;" class="text-muted">
							<?= __('Noch keine Seiten überwacht.') ?>
						</td>
					</tr>
					<?php } ?>
				</tbody>
			</table>

			<hr/>

			<h3><?= __('Neue Seite überwachen') ?></h3>

			<form dojoType="dijit.form.Form" class="tc-add-form">
				<?= \Controls\pluginhandler_tags($this, "add_tracked") ?>

				<script type="dojo/method" event="onSubmit" args="evt">
					evt.preventDefault();
					if (this.validate()) {
						Notify.progress('Speichere...', true);
						xhr.post("backend.php", this.getValues(), (reply) => {
							Notify.info(reply);
							window.setTimeout(() => { location.reload(); }, 1000);
						});
					}
				</script>

				<fieldset>
					<label><?= __('URL:') ?></label>
					<input type="text" dojoType="dijit.form.ValidationTextBox"
						name="tc_url" required="true"
						style="width: 400px"
						placeholder="https://example.com/seite">
				</fieldset>

				<fieldset>
					<label><?= __('Titel:') ?></label>
					<input type="text" dojoType="dijit.form.TextBox"
						name="tc_title"
						style="width: 300px"
						placeholder="<?= __('Optionaler Titel') ?>">
				</fieldset>

				<fieldset>
					<label><?= __('CSS-Selektor:') ?></label>
					<input type="text" dojoType="dijit.form.TextBox"
						name="tc_selector"
						style="width: 300px"
						placeholder="<?= __('z.B. #content, .main, article') ?>">
				</fieldset>

				<fieldset>
					<label><?= __('Prüfintervall:') ?></label>
					<select name="tc_interval" dojoType="dijit.form.Select">
						<option value="1800"><?= __('Alle 30 Minuten') ?></option>
						<option value="3600" selected><?= __('Stündlich') ?></option>
						<option value="7200"><?= __('Alle 2 Stunden') ?></option>
						<option value="21600"><?= __('Alle 6 Stunden') ?></option>
						<option value="43200"><?= __('Alle 12 Stunden') ?></option>
						<option value="86400"><?= __('Täglich') ?></option>
					</select>
				</fieldset>

				<hr/>
				<?= \Controls\submit_tag(__("Hinzufügen")) ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Neue URL zur Überwachung hinzufügen.
	 */
	function add_tracked(): void {
		$url = trim(clean($_POST['tc_url'] ?? ''));
		$title = trim(clean($_POST['tc_title'] ?? ''));
		$selector = trim(clean($_POST['tc_selector'] ?? ''));
		$interval = (int) ($_POST['tc_interval'] ?? 3600);

		if (empty($url)) {
			echo __("Bitte eine URL angeben.");
			return;
		}

		if (!filter_var($url, FILTER_VALIDATE_URL)) {
			echo __("Ungültige URL.");
			return;
		}

		if ($interval < 300) $interval = 300;

		$owner_uid = $_SESSION['uid'];

		// Prüfen ob URL bereits überwacht wird
		$sth = $this->pdo->prepare("SELECT id FROM ttrss_plugin_track_changes
			WHERE owner_uid = ? AND url = ?");
		$sth->execute([$owner_uid, $url]);

		if ($sth->fetch()) {
			echo __("Diese URL wird bereits überwacht.");
			return;
		}

		$sth = $this->pdo->prepare("INSERT INTO ttrss_plugin_track_changes
			(owner_uid, url, title, css_selector, check_interval)
			VALUES (?, ?, ?, ?, ?)");
		$sth->execute([$owner_uid, $url, $title, $selector, $interval]);

		echo __("Seite zur Überwachung hinzugefügt.");
	}

	/**
	 * Überwachte URL entfernen.
	 */
	function remove_tracked(): void {
		$id = (int) ($_POST['track_id'] ?? 0);
		if ($id <= 0) return;

		$sth = $this->pdo->prepare("DELETE FROM ttrss_plugin_track_changes
			WHERE id = ? AND owner_uid = ?");
		$sth->execute([$id, $_SESSION['uid']]);

		echo __("Überwachung entfernt.");
	}

	/**
	 * Einzelne URL sofort prüfen.
	 */
	function check_now(): void {
		$id = (int) ($_POST['track_id'] ?? 0);
		if ($id <= 0) return;

		$sth = $this->pdo->prepare("SELECT * FROM ttrss_plugin_track_changes
			WHERE id = ? AND owner_uid = ?");
		$sth->execute([$id, $_SESSION['uid']]);

		$row = $sth->fetch();
		if (!$row) {
			echo __("Eintrag nicht gefunden.");
			return;
		}

		$this->check_url($row);
		echo __("Prüfung durchgeführt.");
	}

	function api_version() {
		return 2;
	}
}
