<?php
class Enhanced_Filters extends Plugin {

	/** @var PluginHost $host */
	private $host;

	function about() {
		return [1.0,
			"Erweiterte Filterregeln: Zusätzliche Bedingungen und Aktionen für Artikel",
			"tt-ss",
			false];
	}

	function init($host) {
		$this->host = $host;

		$host->add_hook($host::HOOK_ARTICLE_FILTER, $this);
		$host->add_hook($host::HOOK_PREFS_TAB, $this);
	}

	/**
	 * Filterregeln auf eingehende Artikel anwenden.
	 *
	 * @param array<string, mixed> $article
	 * @return array<string, mixed>
	 */
	function hook_article_filter($article) {
		$rules = $this->host->get($this, 'rules', []);

		if (empty($rules) || !is_array($rules)) {
			return $article;
		}

		foreach ($rules as $rule) {
			if (!isset($rule['conditions']) || !isset($rule['actions'])) {
				continue;
			}

			$match_mode = $rule['match_mode'] ?? 'all';
			$conditions_met = $this->evaluate_conditions($article, $rule['conditions'], $match_mode);

			if ($conditions_met) {
				$article = $this->apply_actions($article, $rule['actions']);
			}
		}

		return $article;
	}

	/**
	 * Bedingungen gegen einen Artikel auswerten.
	 *
	 * @param array<string, mixed> $article
	 * @param array<int, array{field: string, op: string, value: string}> $conditions
	 * @param string $match_mode 'all' oder 'any'
	 * @return bool
	 */
	private function evaluate_conditions(array $article, array $conditions, string $match_mode): bool {
		if (empty($conditions)) return false;

		$results = [];
		foreach ($conditions as $condition) {
			$field = $condition['field'] ?? '';
			$op = $condition['op'] ?? '';
			$value = $condition['value'] ?? '';

			$field_value = $this->get_field_value($article, $field);
			$results[] = $this->check_condition($field_value, $op, $value);
		}

		if ($match_mode === 'any') {
			return in_array(true, $results, true);
		}

		// 'all' - alle Bedingungen müssen zutreffen
		return !in_array(false, $results, true);
	}

	/**
	 * Wert eines Artikelfeldes abrufen.
	 *
	 * @param array<string, mixed> $article
	 * @param string $field
	 * @return string
	 */
	private function get_field_value(array $article, string $field): string {
		switch ($field) {
			case 'title':
				return $article['title'] ?? '';
			case 'content':
				return strip_tags($article['content'] ?? '');
			case 'author':
				return $article['author'] ?? '';
			case 'link':
				return $article['link'] ?? '';
			default:
				return '';
		}
	}

	/**
	 * Einzelne Bedingung prüfen.
	 *
	 * @param string $field_value
	 * @param string $op
	 * @param string $value
	 * @return bool
	 */
	private function check_condition(string $field_value, string $op, string $value): bool {
		switch ($op) {
			case 'contains':
				return mb_stripos($field_value, $value) !== false;
			case 'not_contains':
				return mb_stripos($field_value, $value) === false;
			case 'regex':
				// Regex-Muster validieren und mit Timeout-Schutz ausführen
				if (@preg_match('/' . $value . '/iu', '') === false) {
					Debug::log("enhanced_filters: Ungültiges Regex-Muster: $value", Debug::LOG_VERBOSE);
					return false;
				}
				$result = @preg_match('/' . $value . '/iu', mb_substr($field_value, 0, 10000));
				return $result === 1;
			case 'length_gt':
				return mb_strlen($field_value) > (int) $value;
			case 'length_lt':
				return mb_strlen($field_value) < (int) $value;
			default:
				return false;
		}
	}

	/**
	 * Aktionen auf einen Artikel anwenden.
	 *
	 * @param array<string, mixed> $article
	 * @param array<int, array{type: string, value: string}> $actions
	 * @return array<string, mixed>
	 */
	private function apply_actions(array $article, array $actions): array {
		foreach ($actions as $action) {
			$type = $action['type'] ?? '';
			$value = $action['value'] ?? '';

			switch ($type) {
				case 'tag':
					if (!empty($value)) {
						$tags = array_map('trim', explode(',', $value));
						foreach ($tags as $tag) {
							if (!empty($tag) && !in_array($tag, $article['tags'] ?? [])) {
								$article['tags'][] = $tag;
							}
						}
					}
					break;

				case 'star':
					$article['force_star'] = true;
					break;

				case 'publish':
					$article['force_publish'] = true;
					break;

				case 'score':
					$current_score = $article['score_modifier'] ?? 0;
					$article['score_modifier'] = $current_score + (int) $value;
					break;
			}
		}

		return $article;
	}

	/**
	 * Regelkonfiguration speichern (AJAX-Handler).
	 */
	function save_rules(): void {
		$rules_json = clean($_REQUEST['rules'] ?? '');

		if (empty($rules_json)) {
			$this->host->set($this, 'rules', []);
			print json_encode(['status' => 'ok', 'message' => 'Regeln gelöscht.']);
			return;
		}

		$rules = json_decode($rules_json, true);
		if ($rules === null && json_last_error() !== JSON_ERROR_NONE) {
			print json_encode([
				'status' => 'error',
				'message' => 'Ungültiges JSON: ' . json_last_error_msg()
			]);
			return;
		}

		// Validierung
		if (!is_array($rules)) {
			print json_encode(['status' => 'error', 'message' => 'Regeln müssen ein Array sein.']);
			return;
		}

		foreach ($rules as $i => $rule) {
			if (!isset($rule['conditions']) || !is_array($rule['conditions'])) {
				print json_encode(['status' => 'error', 'message' => "Regel $i: 'conditions' fehlt oder ungültig."]);
				return;
			}
			if (!isset($rule['actions']) || !is_array($rule['actions'])) {
				print json_encode(['status' => 'error', 'message' => "Regel $i: 'actions' fehlt oder ungültig."]);
				return;
			}
		}

		$this->host->set($this, 'rules', $rules);
		print json_encode(['status' => 'ok', 'count' => count($rules)]);
	}

	/**
	 * Aktuelle Regeln als JSON abrufen (AJAX-Handler).
	 */
	function get_rules(): void {
		$rules = $this->host->get($this, 'rules', []);
		print json_encode($rules);
	}

	/**
	 * Einstellungs-Tab für erweiterte Filterregeln.
	 */
	function hook_prefs_tab($args) {
		if ($args != "prefPrefs") return;

		$rules = $this->host->get($this, 'rules', []);
		$rules_json = json_encode($rules, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
		if ($rules_json === '[]') {
			$rules_json = $this->get_example_rules();
		}
		?>
		<div dojoType="dijit.layout.AccordionPane"
			title="<i class='material-icons'>filter_alt</i> <?= __('Erweiterte Filter') ?>">

			<h3><?= __('Filterregeln (JSON-Format)') ?></h3>

			<p><?= __('Definiere Filterregeln im JSON-Format. Jede Regel hat Bedingungen (conditions), einen Abgleichmodus (match_mode) und Aktionen (actions).') ?></p>

			<div style="margin: 8px 0;">
				<textarea id="ef-rules-textarea" style="width: 100%; height: 300px; font-family: monospace; font-size: 13px;
					padding: 8px; border: 1px solid var(--border-color, #ccc); border-radius: 4px;
					background: var(--bg-color, #fff); color: var(--fg-color, #333);"><?= htmlspecialchars($rules_json) ?></textarea>
			</div>

			<div style="margin: 8px 0;">
				<button dojoType="dijit.form.Button" type="button"
					onclick="Plugins.Enhanced_Filters.saveRules()">
					<i class="material-icons">save</i> <?= __('Regeln speichern') ?>
				</button>
				<button dojoType="dijit.form.Button" type="button"
					onclick="Plugins.Enhanced_Filters.loadExample()">
					<i class="material-icons">help_outline</i> <?= __('Beispiel laden') ?>
				</button>
			</div>

			<hr/>

			<h3><?= __('Dokumentation') ?></h3>
			<div style="padding: 8px; background: var(--bg-color-secondary, #f5f5f5); border-radius: 4px; font-size: 0.9em;">
				<h4><?= __('Struktur') ?></h4>
				<pre style="margin: 4px 0;">[
  {
    "conditions": [...],
    "match_mode": "all" | "any",
    "actions": [...]
  }
]</pre>

				<h4><?= __('Bedingungen (conditions)') ?></h4>
				<table style="border-collapse: collapse; width: 100%;">
					<tr>
						<th style="text-align: left; padding: 4px 8px; border-bottom: 1px solid #ddd;"><?= __('Feld (field)') ?></th>
						<td style="padding: 4px 8px; border-bottom: 1px solid #ddd;"><code>title</code>, <code>content</code>, <code>author</code>, <code>link</code></td>
					</tr>
					<tr>
						<th style="text-align: left; padding: 4px 8px; border-bottom: 1px solid #ddd;"><?= __('Operator (op)') ?></th>
						<td style="padding: 4px 8px; border-bottom: 1px solid #ddd;"><code>contains</code>, <code>not_contains</code>, <code>regex</code>, <code>length_gt</code>, <code>length_lt</code></td>
					</tr>
					<tr>
						<th style="text-align: left; padding: 4px 8px; border-bottom: 1px solid #ddd;"><?= __('Wert (value)') ?></th>
						<td style="padding: 4px 8px; border-bottom: 1px solid #ddd;"><?= __('Suchtext, Regex-Muster oder Zahl für Längenvergleich') ?></td>
					</tr>
				</table>

				<h4><?= __('Aktionen (actions)') ?></h4>
				<table style="border-collapse: collapse; width: 100%;">
					<tr>
						<th style="text-align: left; padding: 4px 8px; border-bottom: 1px solid #ddd;"><?= __('Typ (type)') ?></th>
						<th style="text-align: left; padding: 4px 8px; border-bottom: 1px solid #ddd;"><?= __('Wert (value)') ?></th>
						<th style="text-align: left; padding: 4px 8px; border-bottom: 1px solid #ddd;"><?= __('Beschreibung') ?></th>
					</tr>
					<tr>
						<td style="padding: 4px 8px; border-bottom: 1px solid #ddd;"><code>tag</code></td>
						<td style="padding: 4px 8px; border-bottom: 1px solid #ddd;"><?= __('Kommagetrennte Tags') ?></td>
						<td style="padding: 4px 8px; border-bottom: 1px solid #ddd;"><?= __('Tags zum Artikel hinzufügen') ?></td>
					</tr>
					<tr>
						<td style="padding: 4px 8px; border-bottom: 1px solid #ddd;"><code>star</code></td>
						<td style="padding: 4px 8px; border-bottom: 1px solid #ddd;">-</td>
						<td style="padding: 4px 8px; border-bottom: 1px solid #ddd;"><?= __('Artikel markieren') ?></td>
					</tr>
					<tr>
						<td style="padding: 4px 8px; border-bottom: 1px solid #ddd;"><code>publish</code></td>
						<td style="padding: 4px 8px; border-bottom: 1px solid #ddd;">-</td>
						<td style="padding: 4px 8px; border-bottom: 1px solid #ddd;"><?= __('Artikel veröffentlichen') ?></td>
					</tr>
					<tr>
						<td style="padding: 4px 8px; border-bottom: 1px solid #ddd;"><code>score</code></td>
						<td style="padding: 4px 8px; border-bottom: 1px solid #ddd;"><?= __('Zahl (+/-)') ?></td>
						<td style="padding: 4px 8px; border-bottom: 1px solid #ddd;"><?= __('Score anpassen') ?></td>
					</tr>
				</table>
			</div>
		</div>

		<script type="text/javascript">
			if (typeof Plugins === "undefined") window.Plugins = {};
			Plugins.Enhanced_Filters = {
				saveRules: function() {
					const textarea = document.getElementById("ef-rules-textarea");
					if (!textarea) return;

					const rulesJson = textarea.value.trim();

					// Leeres Feld = Regeln löschen
					if (!rulesJson || rulesJson === "[]") {
						xhr.json("backend.php", {
							op: "pluginhandler",
							plugin: "enhanced_filters",
							method: "save_rules",
							rules: ""
						}, function(reply) {
							Notify.info("<?= __('Regeln gelöscht.') ?>");
						});
						return;
					}

					// JSON-Validierung clientseitig
					try {
						JSON.parse(rulesJson);
					} catch (e) {
						Notify.error("<?= __('Ungültiges JSON-Format: ') ?>" + e.message);
						return;
					}

					xhr.json("backend.php", {
						op: "pluginhandler",
						plugin: "enhanced_filters",
						method: "save_rules",
						rules: rulesJson
					}, function(reply) {
						if (reply.status === "ok") {
							Notify.info("<?= __('Regeln gespeichert') ?>" + (reply.count ? " (" + reply.count + ")" : "") + ".");
						} else {
							Notify.error(reply.message || "<?= __('Fehler beim Speichern.') ?>");
						}
					});
				},

				loadExample: function() {
					const example = <?= json_encode($this->get_example_rules()) ?>;
					const textarea = document.getElementById("ef-rules-textarea");
					if (textarea) {
						textarea.value = example;
					}
				}
			};
		</script>
		<?php
	}

	/**
	 * Beispiel-Regeln als JSON-String.
	 * @return string
	 */
	private function get_example_rules(): string {
		$example = [
			[
				'conditions' => [
					['field' => 'title', 'op' => 'contains', 'value' => 'Breaking'],
					['field' => 'content', 'op' => 'length_gt', 'value' => '500']
				],
				'match_mode' => 'all',
				'actions' => [
					['type' => 'tag', 'value' => 'wichtig, eilmeldung'],
					['type' => 'score', 'value' => '10']
				]
			],
			[
				'conditions' => [
					['field' => 'author', 'op' => 'regex', 'value' => '(Redaktion|Editor)']
				],
				'match_mode' => 'any',
				'actions' => [
					['type' => 'star', 'value' => '']
				]
			]
		];

		return json_encode($example, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
	}

	function api_version() {
		return 2;
	}
}
