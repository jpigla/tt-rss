<?php
class Ai_Prompts extends Plugin {

	/** @var PluginHost $host */
	private $host;

	function about() {
		return [1.0,
			"Benutzerdefinierte KI-Prompts auf Artikel anwenden (benötigt ai_core)",
			"tt-ss",
			false];
	}

	function init($host) {
		$this->host = $host;

		$host->add_hook($host::HOOK_ARTICLE_BUTTON, $this);
		$host->add_hook($host::HOOK_PREFS_TAB, $this);
	}

	function get_js() {
		return file_get_contents(__DIR__ . "/ai_prompts.js");
	}

	function get_css() {
		return file_get_contents(__DIR__ . "/ai_prompts.css");
	}

	/**
	 * Gibt die gespeicherten Prompts des Benutzers zurück.
	 * @return array<int, array{id: string, name: string, template: string}>
	 */
	private function get_prompts(): array {
		$prompts = $this->host->get($this, "prompts", []);
		return is_array($prompts) ? $prompts : [];
	}

	/**
	 * Speichert die Prompts des Benutzers.
	 * @param array $prompts
	 */
	private function save_prompts(array $prompts): void {
		$this->host->set($this, "prompts", $prompts);
	}

	function hook_article_button($line) {
		$id = (int) $line['id'];

		return "<i class='material-icons ai-prompts-btn'
			data-article-id='" . $id . "'
			onclick=\"Plugins.Ai_Prompts.showMenu(" . $id . ")\"
			style='cursor: pointer'
			title=\"" . __('KI-Prompt') . "\">psychology</i>";
	}

	/**
	 * Gibt die Prompt-Liste per AJAX zurück.
	 */
	function get_prompt_list(): void {
		print json_encode($this->get_prompts());
	}

	/**
	 * Führt einen Prompt auf einem Artikel aus.
	 */
	function execute(): void {
		$article_id = (int) clean($_REQUEST['article_id'] ?? 0);
		$prompt_id = clean($_REQUEST['prompt_id'] ?? '');
		$owner_uid = $_SESSION['uid'];

		if ($article_id <= 0 || empty($prompt_id)) {
			print json_encode(["error" => "Ungültige Parameter"]);
			return;
		}

		if (!PluginHost::getInstance()->get_plugin('ai_core')) {
			print json_encode(["error" => "Plugin ai_core ist nicht aktiviert"]);
			return;
		}

		// Prompt-Template finden
		$prompts = $this->get_prompts();
		$template = null;
		$prompt_name = '';
		foreach ($prompts as $p) {
			if ($p['id'] === $prompt_id) {
				$template = $p['template'];
				$prompt_name = $p['name'];
				break;
			}
		}

		if ($template === null) {
			print json_encode(["error" => "Prompt nicht gefunden"]);
			return;
		}

		// Artikelinhalt laden
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

		$content = strip_tags($article['content']);
		if (mb_strlen($content) > 8000) {
			$content = mb_substr($content, 0, 8000) . '...';
		}

		// Platzhalter ersetzen
		$user_message = str_replace(
			['{{title}}', '{{content}}'],
			[$article['title'], $content],
			$template
		);

		$result = Ai_Core::complete(
			"Du bist ein hilfreicher Assistent. Führe die folgende Anweisung aus.",
			$user_message,
			1024
		);

		if ($result === false) {
			print json_encode(["error" => "KI-Anfrage fehlgeschlagen. Bitte Konfiguration prüfen."]);
			return;
		}

		print json_encode([
			"result" => $result,
			"prompt_name" => $prompt_name
		]);
	}

	/**
	 * Speichert einen neuen oder bearbeiteten Prompt (AJAX).
	 */
	function save_prompt(): void {
		$id = clean($_REQUEST['prompt_id'] ?? '');
		$name = clean($_REQUEST['name'] ?? '');
		$template = clean($_REQUEST['template'] ?? '');

		if (empty($name) || empty($template)) {
			print json_encode(["error" => "Name und Template dürfen nicht leer sein"]);
			return;
		}

		$prompts = $this->get_prompts();

		if (empty($id)) {
			// Neuer Prompt
			$id = uniqid('prompt_');
			$prompts[] = ['id' => $id, 'name' => $name, 'template' => $template];
		} else {
			// Bestehender Prompt bearbeiten
			foreach ($prompts as &$p) {
				if ($p['id'] === $id) {
					$p['name'] = $name;
					$p['template'] = $template;
					break;
				}
			}
			unset($p);
		}

		$this->save_prompts($prompts);
		print json_encode(["ok" => true, "prompts" => $prompts]);
	}

	/**
	 * Löscht einen Prompt (AJAX).
	 */
	function delete_prompt(): void {
		$id = clean($_REQUEST['prompt_id'] ?? '');

		$prompts = $this->get_prompts();
		$prompts = array_values(array_filter($prompts, fn($p) => $p['id'] !== $id));

		$this->save_prompts($prompts);
		print json_encode(["ok" => true, "prompts" => $prompts]);
	}

	function hook_prefs_tab($args) {
		if ($args != "prefPrefs") return;

		$prompts = $this->get_prompts();

		?>
		<div dojoType="dijit.layout.AccordionPane"
			title="<i class='material-icons'>psychology</i> <?= __('KI-Prompts verwalten') ?>">

			<?= format_notice(__("Erstelle eigene Prompt-Vorlagen, die auf Artikel angewendet werden können. Verwende {{title}} und {{content}} als Platzhalter.")) ?>

			<div id="ai-prompts-list">
				<?php if (empty($prompts)) { ?>
					<p class="text-muted"><?= __('Noch keine Prompts erstellt.') ?></p>
				<?php } else { ?>
					<table width="100%" class="prefPromptsTable">
						<thead>
							<tr>
								<th><?= __('Name') ?></th>
								<th><?= __('Vorlage') ?></th>
								<th width="80"><?= __('Aktionen') ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($prompts as $p) { ?>
								<tr>
									<td><?= htmlspecialchars($p['name']) ?></td>
									<td><code><?= htmlspecialchars(mb_substr($p['template'], 0, 100)) ?><?= mb_strlen($p['template']) > 100 ? '...' : '' ?></code></td>
									<td>
										<i class="material-icons" style="cursor:pointer"
											onclick="Plugins.Ai_Prompts.editPrompt('<?= htmlspecialchars($p['id']) ?>')"
											title="<?= __('Bearbeiten') ?>">edit</i>
										<i class="material-icons" style="cursor:pointer"
											onclick="Plugins.Ai_Prompts.deletePrompt('<?= htmlspecialchars($p['id']) ?>')"
											title="<?= __('Löschen') ?>">delete</i>
									</td>
								</tr>
							<?php } ?>
						</tbody>
					</table>
				<?php } ?>
			</div>

			<hr/>

			<form dojoType="dijit.form.Form" id="ai-prompts-add-form">
				<?= \Controls\pluginhandler_tags($this, "save_prompt") ?>

				<script type="dojo/method" event="onSubmit" args="evt">
					evt.preventDefault();
					if (this.validate()) {
						Notify.progress('Speichere Prompt...');
						xhr.post("backend.php", this.getValues(), (reply) => {
							Notify.info('Prompt gespeichert');
							window.location.reload();
						});
					}
				</script>

				<fieldset>
					<label><?= __('Name:') ?></label>
					<input dojoType="dijit.form.TextBox"
						name="name"
						style="width: 300px"
						placeholder="<?= __('z.B. Kernaussage extrahieren') ?>"
						required="true">
				</fieldset>

				<fieldset>
					<label><?= __('Vorlage:') ?></label>
					<textarea dojoType="dijit.form.SimpleTextarea"
						name="template"
						style="width: 100%; height: 80px"
						placeholder="<?= __('z.B. Fasse den Artikel {{title}} zusammen: {{content}}') ?>"
						required="true"></textarea>
				</fieldset>

				<?= \Controls\submit_tag(__("Prompt hinzufügen")) ?>
			</form>
		</div>
		<?php
	}

	function api_version() {
		return 2;
	}
}
