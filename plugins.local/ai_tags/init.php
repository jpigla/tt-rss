<?php
class Ai_Tags extends Plugin {

	/** @var PluginHost $host */
	private $host;

	/** Deutsche Stoppwörter für TF-IDF Auto-Tagging */
	const STOPWORDS = [
		'der','die','das','ein','eine','einer','eines','einem','einen',
		'und','oder','aber','doch','sondern','nicht','kein','keine','keiner',
		'ist','sind','war','waren','wird','werden','hat','haben','hatte',
		'mit','von','für','auf','an','in','zu','aus','bei','nach','über',
		'unter','vor','zwischen','durch','um','gegen','ohne','bis',
		'ich','du','er','sie','es','wir','ihr','sich','man',
		'den','dem','des','dass','wenn','als','wie','so','auch','noch',
		'schon','nur','dann','weil','ob','mehr','sehr','kann','können',
		'muss','müssen','soll','sollen','will','wollen','sein','seine',
		'seine','seiner','seinem','seinen','ihre','ihrer','ihrem','ihren',
		'the','a','an','is','are','was','were','be','been','being',
		'have','has','had','do','does','did','will','would','could','should',
		'may','might','shall','can','need','dare','ought','used',
		'to','of','in','for','on','with','at','by','from','up','about',
		'into','over','after','and','but','or','not','no','nor','so',
		'that','this','these','those','it','its','he','she','they','we',
		'you','his','her','their','our','my','your','what','which','who',
	];

	function about() {
		return [1.0,
			"KI-gestützte Tag-Vorschläge für Artikel (benötigt ai_core)",
			"tt-ss",
			false];
	}

	function init($host) {
		$this->host = $host;

		$host->add_hook($host::HOOK_ARTICLE_BUTTON, $this);
		$host->add_hook($host::HOOK_ARTICLE_FILTER, $this);
		$host->add_hook($host::HOOK_PREFS_TAB, $this);
	}

	function get_js() {
		return file_get_contents(__DIR__ . "/ai_tags.js");
	}

	function hook_article_button($line) {
		$id = $line['id'];

		return "<i class='material-icons ai-tags-btn'
			data-article-id='$id'
			onclick=\"Plugins.Ai_Tags.suggest($id)\"
			style='cursor: pointer'
			title=\"" . __('Tags vorschlagen') . "\">auto_fix_high</i>";
	}

	/**
	 * KI-basierte Tag-Vorschläge per AJAX.
	 */
	function suggest(): void {
		$article_id = (int) clean($_REQUEST['article_id'] ?? 0);
		$owner_uid = $_SESSION['uid'];

		if ($article_id <= 0) {
			print json_encode(["error" => "Ungültige Artikel-ID"]);
			return;
		}

		if (!PluginHost::getInstance()->get_plugin('ai_core')) {
			print json_encode(["error" => "Plugin ai_core ist nicht aktiviert"]);
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
		if (mb_strlen($content) > 6000) {
			$content = mb_substr($content, 0, 6000) . '...';
		}

		$tag_count = (int) $this->host->get($this, 'tag_count', 5);

		$result = Ai_Core::complete(
			"Schlage $tag_count passende Tags für diesen Artikel vor. Gib nur die Tags zurück, kommagetrennt, in Kleinbuchstaben.",
			"Titel: " . $article['title'] . "\n\nInhalt:\n" . $content,
			200
		);

		if ($result === false) {
			print json_encode(["error" => "KI-Anfrage fehlgeschlagen."]);
			return;
		}

		// Tags parsen
		$tags = array_unique(array_filter(
			array_map(function($t) {
				return mb_strtolower(trim($t, " \t\n\r\0\x0B.-#"));
			}, explode(',', $result)),
			fn($t) => mb_strlen($t) > 0 && mb_strlen($t) < 100
		));

		print json_encode(["tags" => array_values($tags)]);
	}

	/**
	 * Tags auf einen Artikel anwenden (AJAX).
	 */
	function apply_tags(): void {
		$article_id = (int) clean($_REQUEST['article_id'] ?? 0);
		$tags_str = clean($_REQUEST['tags'] ?? '');
		$owner_uid = $_SESSION['uid'];

		if ($article_id <= 0) {
			print json_encode(["error" => "Ungültige Artikel-ID"]);
			return;
		}

		// int_id holen
		$sth = $this->pdo->prepare("SELECT int_id FROM ttrss_user_entries
			WHERE ref_id = ? AND owner_uid = ?");
		$sth->execute([$article_id, $owner_uid]);
		$row = $sth->fetch();
		if (!$row) {
			print json_encode(["error" => "Artikel nicht gefunden"]);
			return;
		}

		$int_id = $row['int_id'];

		// Bestehende Tags laden
		$sth = $this->pdo->prepare("SELECT tag_name FROM ttrss_tags
			WHERE post_int_id = ? AND owner_uid = ?");
		$sth->execute([$int_id, $owner_uid]);

		$existing_tags = [];
		while ($r = $sth->fetch()) {
			$existing_tags[] = $r['tag_name'];
		}

		// Neue Tags hinzufügen (nicht doppelt)
		$new_tags = array_unique(array_filter(
			array_map('trim', explode(',', $tags_str)),
			fn($t) => mb_strlen($t) > 0
		));

		$sth = $this->pdo->prepare("INSERT INTO ttrss_tags (tag_name, owner_uid, post_int_id)
			VALUES (?, ?, ?)");

		$added = [];
		foreach ($new_tags as $tag) {
			$tag = mb_strtolower(mb_substr($tag, 0, 250));
			if (!in_array($tag, $existing_tags)) {
				$sth->execute([$tag, $owner_uid, $int_id]);
				$added[] = $tag;
				$existing_tags[] = $tag;
			}
		}

		// Tag-Cache aktualisieren
		$tag_cache = implode(',', $existing_tags);
		$sth2 = $this->pdo->prepare("UPDATE ttrss_user_entries SET tag_cache = ?
			WHERE ref_id = ? AND owner_uid = ?");
		$sth2->execute([$tag_cache, $article_id, $owner_uid]);

		print json_encode([
			"ok" => true,
			"added" => $added,
			"all_tags" => $existing_tags
		]);
	}

	/**
	 * Auto-Tagging beim Import per TF-IDF (ohne KI).
	 */
	function hook_article_filter($article) {
		$auto_tag = $this->host->get($this, 'auto_tag', false);
		if (!$auto_tag) return $article;

		$content = strip_tags($article['content'] ?? '');
		$title = $article['title'] ?? '';
		$text = $title . ' ' . $content;

		$tags = $this->extract_keywords($text, 3);

		if (!empty($tags)) {
			if (!isset($article['tags'])) {
				$article['tags'] = [];
			}
			$article['tags'] = array_unique(array_merge($article['tags'], $tags));
		}

		return $article;
	}

	/**
	 * Extrahiert Keywords per einfacher Wortfrequenz.
	 * @param string $text
	 * @param int $count
	 * @return array<string>
	 */
	private function extract_keywords(string $text, int $count): array {
		$text = mb_strtolower($text);
		// Nur Wörter extrahieren
		preg_match_all('/\b[\wäöüß]{3,}\b/u', $text, $matches);

		if (empty($matches[0])) return [];

		$words = $matches[0];
		$stopwords = self::STOPWORDS;

		// Worthäufigkeit zählen
		$freq = [];
		foreach ($words as $word) {
			if (in_array($word, $stopwords)) continue;
			if (mb_strlen($word) < 4) continue;
			$freq[$word] = ($freq[$word] ?? 0) + 1;
		}

		arsort($freq);

		return array_slice(array_keys($freq), 0, $count);
	}

	function hook_prefs_tab($args) {
		if ($args != "prefPrefs") return;

		$auto_tag = $this->host->get($this, 'auto_tag', false);
		$tag_count = $this->host->get($this, 'tag_count', 5);

		?>
		<div dojoType="dijit.layout.AccordionPane"
			title="<i class='material-icons'>auto_fix_high</i> <?= __('KI-Tags') ?>">

			<form dojoType="dijit.form.Form">

				<?= \Controls\pluginhandler_tags($this, "save") ?>

				<script type="dojo/method" event="onSubmit" args="evt">
					evt.preventDefault();
					if (this.validate()) {
						Notify.progress('Saving data...', true);
						xhr.post("backend.php", this.getValues(), (reply) => {
							Notify.info(reply);
						})
					}
				</script>

				<?= format_notice(__("KI-gestützte Tag-Vorschläge konfigurieren. Auto-Tagging nutzt TF-IDF (ohne KI-Aufruf).")) ?>

				<fieldset>
					<label><?= __("Anzahl vorgeschlagener Tags:") ?></label>
					<input dojoType="dijit.form.NumberSpinner"
						name="tag_count"
						value="<?= (int)$tag_count ?>"
						constraints="{min: 1, max: 10}"
						style="width: 80px">
				</fieldset>

				<fieldset>
					<label><?= __("Auto-Tagging beim Import:") ?></label>
					<input dojoType="dijit.form.CheckBox"
						type="checkbox"
						name="auto_tag"
						<?= $auto_tag ? 'checked="checked"' : '' ?>>
					<span><?= __('Neue Artikel automatisch mit Keywords taggen (TF-IDF, ohne KI)') ?></span>
				</fieldset>

				<hr/>

				<?= \Controls\submit_tag(__("Speichern")) ?>
			</form>
		</div>
		<?php
	}

	function save(): void {
		$this->host->set($this, 'tag_count', (int) clean($_POST['tag_count'] ?? 5));
		$this->host->set($this, 'auto_tag', isset($_POST['auto_tag']) && $_POST['auto_tag'] === 'on');

		echo __("KI-Tags-Konfiguration gespeichert.");
	}

	function api_version() {
		return 2;
	}
}
