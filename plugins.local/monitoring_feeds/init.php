<?php
class Monitoring_Feeds extends Plugin {

	/** @var PluginHost $host */
	private $host;

	/** @var string Prefix für Monitor-Tags */
	const TAG_PREFIX = 'monitor:';

	function about() {
		return [1.0,
			"Keyword-basiertes Monitoring: Artikel werden automatisch getaggt wenn sie konfigurierte Suchbegriffe enthalten",
			"tt-ss",
			false];
	}

	function init($host) {
		$this->host = $host;

		$host->add_hook($host::HOOK_ARTICLE_FILTER, $this);
		$host->add_hook($host::HOOK_PREFS_TAB, $this);
	}

	function get_css() {
		return file_get_contents(__DIR__ . "/monitoring_feeds.css");
	}

	/**
	 * Gibt die konfigurierten Monitore zurück.
	 * @return array<int, array{name: string, keywords: string}>
	 */
	private function get_monitors(): array {
		$monitors = $this->host->get($this, "monitors");
		if (!is_array($monitors)) return [];
		return $monitors;
	}

	/**
	 * Prüft eingehende Artikel auf konfigurierte Keywords und fügt Tags hinzu.
	 * @param array<string, mixed> $article
	 * @return array<string, mixed>
	 */
	function hook_article_filter($article) {
		$monitors = $this->get_monitors();
		if (empty($monitors)) return $article;

		$title = mb_strtolower($article['title'] ?? '');
		$content = mb_strtolower(strip_tags($article['content'] ?? ''));
		$searchable = $title . ' ' . $content;

		foreach ($monitors as $monitor) {
			$name = $monitor['name'] ?? '';
			$keywords_str = $monitor['keywords'] ?? '';

			if (empty($name) || empty($keywords_str)) continue;

			$keywords = array_map('trim', explode(',', $keywords_str));

			foreach ($keywords as $keyword) {
				$keyword = mb_strtolower(trim($keyword));
				if (mb_strlen($keyword) < 2) continue;

				if (mb_strpos($searchable, $keyword) !== false) {
					// Tag hinzufügen
					$tag = self::TAG_PREFIX . $name;
					if (!in_array($tag, $article['tags'] ?? [])) {
						$article['tags'][] = $tag;
					}
					// Ein Treffer pro Monitor reicht
					break;
				}
			}
		}

		return $article;
	}

	function hook_prefs_tab($args) {
		if ($args != "prefFeeds") return;

		$monitors = $this->get_monitors();

		?>
		<div dojoType="dijit.layout.AccordionPane"
			title="<i class='material-icons'>monitoring</i> <?= __('Keyword-Monitoring') ?>">

			<form dojoType="dijit.form.Form" id="monitoring_feeds_form">

				<?= \Controls\pluginhandler_tags($this, "save_monitors") ?>

				<script type="dojo/method" event="onSubmit" args="evt">
					evt.preventDefault();
					if (this.validate()) {
						Notify.progress('Speichere...', true);
						xhr.post("backend.php", this.getValues(), (reply) => {
							Notify.info(reply);
						});
					}
				</script>

				<?= format_notice(__("Definiere Keyword-Sets zur Überwachung. Artikel, die eines der Keywords enthalten, werden automatisch mit dem entsprechenden Tag versehen. Keywords kommagetrennt eingeben.")) ?>

				<table class="monitoring-table" width="100%">
					<thead>
						<tr>
							<th><?= __('Monitor-Name') ?></th>
							<th><?= __('Keywords (kommagetrennt)') ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						// Bestehende Monitore anzeigen + leere Zeilen
						$total_rows = max(count($monitors), 1);
						$total_rows = min($total_rows + 3, 15); // Bis zu 3 zusätzliche leere Zeilen

						for ($i = 0; $i < $total_rows; $i++) {
							$name = htmlspecialchars($monitors[$i]['name'] ?? '');
							$keywords = htmlspecialchars($monitors[$i]['keywords'] ?? '');
						?>
						<tr>
							<td>
								<input type="text" dojoType="dijit.form.TextBox"
									name="mon_name[]"
									style="width: 200px"
									placeholder="<?= __('z.B. Tech News') ?>"
									value="<?= $name ?>">
							</td>
							<td>
								<input type="text" dojoType="dijit.form.TextBox"
									name="mon_keywords[]"
									style="width: 400px"
									placeholder="<?= __('z.B. KI, Machine Learning, GPT') ?>"
									value="<?= $keywords ?>">
							</td>
						</tr>
						<?php } ?>
					</tbody>
				</table>

				<p class="text-muted">
					<?= __('Artikel mit Treffern erhalten den Tag') ?>
					<code>monitor:&lt;Name&gt;</code>.
					<?= __('Nutze die Tag-Suche in der Seitenleiste zum Filtern.') ?>
				</p>

				<hr/>
				<?= \Controls\submit_tag(__("Speichern")) ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Monitore speichern.
	 */
	function save_monitors(): void {
		$names = $_POST['mon_name'] ?? [];
		$keywords_arr = $_POST['mon_keywords'] ?? [];

		$monitors = [];
		for ($i = 0; $i < count($names); $i++) {
			$name = trim($names[$i] ?? '');
			$keywords = trim($keywords_arr[$i] ?? '');

			if (!empty($name) && !empty($keywords)) {
				// Keywords normalisieren
				$keywords = implode(', ', array_filter(
					array_map('trim', explode(',', $keywords)),
					fn($k) => mb_strlen($k) >= 2
				));
				if (!empty($keywords)) {
					$monitors[] = ['name' => $name, 'keywords' => $keywords];
				}
			}
		}

		$this->host->set($this, "monitors", $monitors);

		echo __("Monitoring-Konfiguration gespeichert.");
	}

	function api_version() {
		return 2;
	}
}
