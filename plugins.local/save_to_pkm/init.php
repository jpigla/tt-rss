<?php
class Save_To_Pkm extends Plugin {

	/** @var PluginHost $host */
	private $host;

	/** @var array<string, string> Unterstützte PKM-Dienste */
	private array $services = [
		'readwise' => 'Readwise',
		'notion' => 'Notion',
		'obsidian' => 'Obsidian',
	];

	function about() {
		return [1.0,
			"Artikel an PKM-Tools senden (Readwise, Notion, Obsidian)",
			"tt-ss",
			false];
	}

	function init($host) {
		$this->host = $host;

		$host->add_hook($host::HOOK_ARTICLE_BUTTON, $this);
		$host->add_hook($host::HOOK_PREFS_TAB, $this);
	}

	function get_js() {
		return file_get_contents(__DIR__ . "/save_to_pkm.js");
	}

	function get_css() {
		return file_get_contents(__DIR__ . "/save_to_pkm.css");
	}

	/**
	 * @param array<string, mixed> $line
	 * @return string
	 */
	function hook_article_button($line) {
		$id = (int) $line['id'];

		return "<i class='material-icons pkm-export-btn'
			onclick=\"Plugins.Save_To_Pkm.showMenu(event, " . $id . ")\"
			style='cursor: pointer'
			title=\"" . __('An PKM-Tool senden...') . "\">auto_stories</i>";
	}

	/**
	 * Artikel an PKM-Dienst senden.
	 */
	function save_article(): void {
		$article_id = (int)clean($_REQUEST['article_id'] ?? 0);
		$service = clean($_REQUEST['service'] ?? '');

		if ($article_id <= 0 || empty($service) || !isset($this->services[$service])) {
			print json_encode(["error" => __("Ungültige Parameter.")]);
			return;
		}

		$sth = $this->pdo->prepare("SELECT e.title, e.link, e.content, e.author,
				f.title AS feed_title
			FROM ttrss_entries e
			JOIN ttrss_user_entries ue ON ue.ref_id = e.id
			LEFT JOIN ttrss_feeds f ON f.id = ue.feed_id
			WHERE e.id = ? AND ue.owner_uid = ?");
		$sth->execute([$article_id, $_SESSION['uid']]);
		$article = $sth->fetch();

		if (!$article) {
			print json_encode(["error" => __("Artikel nicht gefunden.")]);
			return;
		}

		$config = $this->host->get($this, "service_$service", []);
		if (!is_array($config) || empty($config['enabled'])) {
			print json_encode(["error" => __("Dienst nicht konfiguriert oder deaktiviert.")]);
			return;
		}

		// Highlights laden (falls Annotations-Plugin aktiv)
		$highlights = $this->get_article_highlights($article_id);

		$result = false;

		switch ($service) {
			case 'readwise':
				$result = $this->save_to_readwise($article, $highlights, $config);
				break;
			case 'notion':
				$result = $this->save_to_notion($article, $highlights, $config);
				break;
			case 'obsidian':
				$result = $this->save_to_obsidian($article, $highlights, $config);
				break;
		}

		if ($result === true) {
			print json_encode(["message" => sprintf(__("Artikel an %s gesendet."), $this->services[$service])]);
		} elseif (is_string($result)) {
			// Obsidian URI-Modus: URL zurückgeben
			print json_encode(["message" => __("Obsidian-Link erstellt."), "uri" => $result]);
		} else {
			print json_encode(["error" => sprintf(__("Fehler beim Senden an %s."), $this->services[$service])]);
		}
	}

	/**
	 * Highlights aus dem Annotations-Plugin laden.
	 * @return array<array{text: string, note: string, color: string}>
	 */
	private function get_article_highlights(int $article_id): array {
		$highlights = [];

		try {
			$sth = $this->pdo->prepare("SELECT highlighted_text, note, color
				FROM ttrss_plugin_annotations
				WHERE ref_id = ? AND owner_uid = ?
				ORDER BY created_at ASC");
			$sth->execute([$article_id, $_SESSION['uid']]);
			while ($row = $sth->fetch()) {
				$highlights[] = [
					'text' => $row['highlighted_text'],
					'note' => $row['note'] ?? '',
					'color' => $row['color'] ?? '',
				];
			}
		} catch (\PDOException $e) {
			// Annotations-Plugin nicht installiert — kein Fehler
		}

		return $highlights;
	}

	/**
	 * HTML-Content zu Markdown konvertieren (einfache Variante).
	 */
	private function html_to_markdown(string $html): string {
		$text = $html;

		// Links
		$text = preg_replace('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', '[$2]($1)', $text);
		// Überschriften
		$text = preg_replace('/<h1[^>]*>(.*?)<\/h1>/is', '# $1', $text);
		$text = preg_replace('/<h2[^>]*>(.*?)<\/h2>/is', '## $1', $text);
		$text = preg_replace('/<h3[^>]*>(.*?)<\/h3>/is', '### $1', $text);
		$text = preg_replace('/<h[4-6][^>]*>(.*?)<\/h[4-6]>/is', '#### $1', $text);
		// Fett & Kursiv
		$text = preg_replace('/<(strong|b)>(.*?)<\/\1>/is', '**$2**', $text);
		$text = preg_replace('/<(em|i)>(.*?)<\/\1>/is', '*$2*', $text);
		// Listen
		$text = preg_replace('/<li[^>]*>(.*?)<\/li>/is', '- $1', $text);
		// Bilder
		$text = preg_replace('/<img[^>]+src=["\']([^"\']+)["\'][^>]*\/?>/is', '![]($1)', $text);
		// Absätze und Zeilenumbrüche
		$text = preg_replace('/<br\s*\/?>/i', "\n", $text);
		$text = preg_replace('/<\/p>/i', "\n\n", $text);
		// Restliche Tags entfernen
		$text = strip_tags($text);
		// Mehrfache Leerzeilen reduzieren
		$text = preg_replace('/\n{3,}/', "\n\n", $text);

		return trim($text);
	}

	/**
	 * Readwise Reader API: Artikel und Highlights speichern.
	 */
	private function save_to_readwise(array $article, array $highlights, array $config): bool {
		$token = $config['api_token'] ?? '';
		if (empty($token)) return false;

		// Artikel an Readwise Reader senden
		$payload = [
			'url' => $article['link'],
			'title' => $article['title'],
			'author' => $article['author'] ?? '',
			'html' => $article['content'] ?? '',
			'saved_using' => 'tt-ss',
		];

		$result = UrlHelper::fetch([
			'url' => 'https://readwise.io/api/v3/save/',
			'timeout' => 15,
			'post_query' => json_encode($payload),
			'type' => 'application/json',
			'extra_headers' => ["Authorization: Token $token"],
		]);

		if ($result === false) return false;

		// Highlights synchronisieren (falls vorhanden)
		if (!empty($highlights)) {
			$hl_payload = ['highlights' => []];
			foreach ($highlights as $hl) {
				$hl_entry = [
					'text' => $hl['text'],
					'source_url' => $article['link'],
					'source_type' => 'article',
				];
				if (!empty($hl['note'])) {
					$hl_entry['note'] = $hl['note'];
				}
				$hl_payload['highlights'][] = $hl_entry;
			}

			UrlHelper::fetch([
				'url' => 'https://readwise.io/api/v2/highlights/',
				'timeout' => 15,
				'post_query' => json_encode($hl_payload),
				'type' => 'application/json',
				'extra_headers' => ["Authorization: Token $token"],
			]);
		}

		return true;
	}

	/**
	 * Notion API: Seite in Datenbank erstellen.
	 */
	private function save_to_notion(array $article, array $highlights, array $config): bool {
		$token = $config['api_token'] ?? '';
		$database_id = $config['database_id'] ?? '';

		if (empty($token) || empty($database_id)) return false;

		$markdown = $this->html_to_markdown($article['content'] ?? '');

		// Notion-Blöcke erstellen (max. 2000 Zeichen pro Block)
		$children = [];

		// Hauptinhalt als Absätze (Notion-Limit: 2000 Zeichen pro Block)
		$paragraphs = array_filter(explode("\n\n", $markdown));
		foreach (array_slice($paragraphs, 0, 50) as $para) {
			$children[] = [
				'object' => 'block',
				'type' => 'paragraph',
				'paragraph' => [
					'rich_text' => [[
						'type' => 'text',
						'text' => ['content' => mb_substr(trim($para), 0, 2000)],
					]],
				],
			];
		}

		// Highlights als Callout-Blöcke
		if (!empty($highlights)) {
			$children[] = [
				'object' => 'block',
				'type' => 'heading_2',
				'heading_2' => [
					'rich_text' => [[
						'type' => 'text',
						'text' => ['content' => 'Highlights'],
					]],
				],
			];

			foreach ($highlights as $hl) {
				$hl_text = $hl['text'];
				if (!empty($hl['note'])) {
					$hl_text .= "\n\n📝 " . $hl['note'];
				}
				$children[] = [
					'object' => 'block',
					'type' => 'callout',
					'callout' => [
						'icon' => ['type' => 'emoji', 'emoji' => '💡'],
						'rich_text' => [[
							'type' => 'text',
							'text' => ['content' => mb_substr($hl_text, 0, 2000)],
						]],
					],
				];
			}
		}

		$payload = [
			'parent' => ['database_id' => $database_id],
			'properties' => [
				'Name' => [
					'title' => [[
						'text' => ['content' => mb_substr($article['title'], 0, 200)],
					]],
				],
				'URL' => [
					'url' => $article['link'],
				],
			],
			'children' => array_slice($children, 0, 100), // Notion max 100 Blöcke
		];

		// Optionale Properties (Autor, Feed)
		if (!empty($article['author'])) {
			$payload['properties']['Autor'] = [
				'rich_text' => [[
					'text' => ['content' => mb_substr($article['author'], 0, 200)],
				]],
			];
		}
		if (!empty($article['feed_title'])) {
			$payload['properties']['Quelle'] = [
				'rich_text' => [[
					'text' => ['content' => mb_substr($article['feed_title'], 0, 200)],
				]],
			];
		}

		$result = UrlHelper::fetch([
			'url' => 'https://api.notion.com/v1/pages',
			'timeout' => 20,
			'post_query' => json_encode($payload),
			'type' => 'application/json',
			'extra_headers' => [
				"Authorization: Bearer $token",
				"Notion-Version: 2022-06-28",
			],
		]);

		return $result !== false;
	}

	/**
	 * Obsidian: Markdown-Datei erstellen oder URI generieren.
	 * @return bool|string true bei Datei-Modus, URI-String bei URI-Modus, false bei Fehler
	 */
	private function save_to_obsidian(array $article, array $highlights, array $config) {
		$vault = $config['vault'] ?? '';
		$folder = trim($config['folder'] ?? 'tt-ss', '/');
		$mode = $config['mode'] ?? 'uri';

		if (empty($vault)) return false;

		// Markdown-Inhalt zusammenbauen
		$content = $this->build_obsidian_note($article, $highlights, $folder);

		// Dateiname: Titel bereinigen
		$filename = preg_replace('/[\/\\\\:*?"<>|]/', '-', $article['title']);
		$filename = mb_substr($filename, 0, 100);

		if ($mode === 'file') {
			// Datei-Modus: Direkt in Vault-Verzeichnis schreiben
			$vault_path = $config['vault_path'] ?? '';
			if (empty($vault_path) || !is_dir($vault_path)) return false;

			// Path-Traversal-Schutz: Ordner darf keine .. enthalten
			$folder = str_replace('..', '', $folder);
			$filename = str_replace('..', '', $filename);

			$target_dir = rtrim($vault_path, '/') . '/' . $folder;
			if (!is_dir($target_dir)) {
				@mkdir($target_dir, 0755, true);
			}

			// Sicherstellen, dass Zielverzeichnis innerhalb des Vault liegt
			$resolved_vault = realpath($vault_path);
			$resolved_target = realpath($target_dir);
			if ($resolved_vault === false || $resolved_target === false
				|| strpos($resolved_target, $resolved_vault) !== 0) {
				return false;
			}

			$filepath = $resolved_target . '/' . $filename . '.md';
			$written = @file_put_contents($filepath, $content);

			return $written !== false;
		}

		// URI-Modus: Obsidian-URI generieren
		$uri = 'obsidian://new?vault=' . rawurlencode($vault)
			. '&file=' . rawurlencode($folder . '/' . $filename)
			. '&content=' . rawurlencode(mb_substr($content, 0, 1800));

		return $uri;
	}

	/**
	 * Obsidian-Note im Markdown-Format erstellen.
	 */
	private function build_obsidian_note(array $article, array $highlights, string $folder): string {
		$md = "---\n";
		$md .= "title: \"" . str_replace('"', '\\"', $article['title']) . "\"\n";
		$md .= "url: " . $article['link'] . "\n";
		if (!empty($article['author'])) {
			$md .= "author: \"" . str_replace('"', '\\"', $article['author']) . "\"\n";
		}
		if (!empty($article['feed_title'])) {
			$md .= "source: \"" . str_replace('"', '\\"', $article['feed_title']) . "\"\n";
		}
		$md .= "saved: " . date('Y-m-d H:i') . "\n";
		$md .= "tags: [tt-ss]\n";
		$md .= "---\n\n";

		$md .= "# " . $article['title'] . "\n\n";
		$md .= "> [Originalquelle](" . $article['link'] . ")\n\n";

		$md .= $this->html_to_markdown($article['content'] ?? '');

		if (!empty($highlights)) {
			$md .= "\n\n---\n\n## Highlights\n\n";
			foreach ($highlights as $hl) {
				$md .= "> " . $hl['text'] . "\n";
				if (!empty($hl['note'])) {
					$md .= "\n📝 " . $hl['note'] . "\n";
				}
				$md .= "\n";
			}
		}

		return $md;
	}

	/**
	 * Liste der aktiven PKM-Dienste zurückgeben.
	 */
	function get_services(): void {
		$enabled = [];

		foreach ($this->services as $key => $label) {
			$config = $this->host->get($this, "service_$key", []);
			if (is_array($config) && !empty($config['enabled'])) {
				$entry = ["id" => $key, "label" => $label];
				if ($key === 'obsidian' && ($config['mode'] ?? 'uri') === 'uri') {
					$entry["uri_mode"] = true;
				}
				$enabled[] = $entry;
			}
		}

		print json_encode($enabled);
	}

	function hook_prefs_tab($args) {
		if ($args != "prefPrefs") return;

		$readwise = $this->host->get($this, "service_readwise", []);
		$notion = $this->host->get($this, "service_notion", []);
		$obsidian = $this->host->get($this, "service_obsidian", []);

		?>
		<div dojoType="dijit.layout.AccordionPane"
			title="<i class='material-icons'>auto_stories</i> <?= __('PKM-Export (Readwise, Notion, Obsidian)') ?>">

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

				<!-- Readwise -->
				<header>Readwise</header>
				<fieldset>
					<label>
						<input dojoType="dijit.form.CheckBox" name="readwise_enabled"
							type="checkbox" <?= !empty($readwise['enabled']) ? 'checked' : '' ?>>
						<?= __('Aktiviert') ?>
					</label>
				</fieldset>
				<fieldset>
					<label><?= __('API-Token:') ?></label>
					<input dojoType="dijit.form.TextBox" name="readwise_api_token"
						type="password" style="width: 400px"
						placeholder="Readwise Access Token"
						value="<?= htmlspecialchars($readwise['api_token'] ?? '') ?>">
				</fieldset>
				<fieldset>
					<small><?= __('Token unter readwise.io/access_token erstellen. Highlights werden automatisch synchronisiert, wenn das Annotations-Plugin aktiv ist.') ?></small>
				</fieldset>

				<hr/>

				<!-- Notion -->
				<header>Notion</header>
				<fieldset>
					<label>
						<input dojoType="dijit.form.CheckBox" name="notion_enabled"
							type="checkbox" <?= !empty($notion['enabled']) ? 'checked' : '' ?>>
						<?= __('Aktiviert') ?>
					</label>
				</fieldset>
				<fieldset>
					<label><?= __('Integration-Token:') ?></label>
					<input dojoType="dijit.form.TextBox" name="notion_api_token"
						type="password" style="width: 400px"
						placeholder="secret_..."
						value="<?= htmlspecialchars($notion['api_token'] ?? '') ?>">
				</fieldset>
				<fieldset>
					<label><?= __('Datenbank-ID:') ?></label>
					<input dojoType="dijit.form.TextBox" name="notion_database_id"
						style="width: 400px"
						placeholder="32-stellige ID aus der Datenbank-URL"
						value="<?= htmlspecialchars($notion['database_id'] ?? '') ?>">
				</fieldset>
				<fieldset>
					<small><?= __('Notion-Integration unter notion.so/my-integrations erstellen. Die Datenbank muss die Properties "Name" (Titel), "URL" (URL) haben. Optional: "Autor" (Text), "Quelle" (Text).') ?></small>
				</fieldset>

				<hr/>

				<!-- Obsidian -->
				<header>Obsidian</header>
				<fieldset>
					<label>
						<input dojoType="dijit.form.CheckBox" name="obsidian_enabled"
							type="checkbox" <?= !empty($obsidian['enabled']) ? 'checked' : '' ?>>
						<?= __('Aktiviert') ?>
					</label>
				</fieldset>
				<fieldset>
					<label><?= __('Vault-Name:') ?></label>
					<input dojoType="dijit.form.TextBox" name="obsidian_vault"
						style="width: 300px"
						placeholder="Mein Vault"
						value="<?= htmlspecialchars($obsidian['vault'] ?? '') ?>">
				</fieldset>
				<fieldset>
					<label><?= __('Ordner im Vault:') ?></label>
					<input dojoType="dijit.form.TextBox" name="obsidian_folder"
						style="width: 300px"
						placeholder="tt-ss"
						value="<?= htmlspecialchars($obsidian['folder'] ?? 'tt-ss') ?>">
				</fieldset>
				<fieldset>
					<label><?= __('Modus:') ?></label>
					<select dojoType="dijit.form.Select" name="obsidian_mode" style="width: 200px">
						<option value="uri" <?= ($obsidian['mode'] ?? 'uri') === 'uri' ? 'selected' : '' ?>>
							<?= __('URI (Browser öffnet Obsidian)') ?>
						</option>
						<option value="file" <?= ($obsidian['mode'] ?? 'uri') === 'file' ? 'selected' : '' ?>>
							<?= __('Datei (Direkt in Vault schreiben)') ?>
						</option>
					</select>
				</fieldset>
				<fieldset>
					<label><?= __('Vault-Pfad (nur Datei-Modus):') ?></label>
					<input dojoType="dijit.form.TextBox" name="obsidian_vault_path"
						style="width: 400px"
						placeholder="/home/user/Documents/Obsidian/MeinVault"
						value="<?= htmlspecialchars($obsidian['vault_path'] ?? '') ?>">
				</fieldset>
				<fieldset>
					<small><?= __('URI-Modus: Öffnet Obsidian über obsidian://-Link (Inhalt auf ~1800 Zeichen begrenzt). Datei-Modus: Server schreibt Markdown direkt ins Vault-Verzeichnis (vollständiger Inhalt).') ?></small>
				</fieldset>

				<hr/>
				<?= \Controls\submit_tag(__("Speichern")) ?>
			</form>
		</div>
		<?php
	}

	function save(): void {
		$this->host->set($this, "service_readwise", [
			'enabled' => checkbox_to_sql_bool($_POST["readwise_enabled"] ?? ""),
			'api_token' => clean($_POST["readwise_api_token"] ?? ""),
		]);

		$this->host->set($this, "service_notion", [
			'enabled' => checkbox_to_sql_bool($_POST["notion_enabled"] ?? ""),
			'api_token' => clean($_POST["notion_api_token"] ?? ""),
			'database_id' => clean($_POST["notion_database_id"] ?? ""),
		]);

		$this->host->set($this, "service_obsidian", [
			'enabled' => checkbox_to_sql_bool($_POST["obsidian_enabled"] ?? ""),
			'vault' => clean($_POST["obsidian_vault"] ?? ""),
			'folder' => clean($_POST["obsidian_folder"] ?? "tt-ss"),
			'mode' => clean($_POST["obsidian_mode"] ?? "uri"),
			'vault_path' => clean($_POST["obsidian_vault_path"] ?? ""),
		]);

		echo __("PKM-Konfiguration gespeichert.");
	}

	function api_version() {
		return 2;
	}
}
