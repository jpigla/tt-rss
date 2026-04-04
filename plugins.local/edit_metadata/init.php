<?php
class Edit_Metadata extends Plugin {

	/** @var PluginHost $host */
	private $host;

	function about() {
		return [1.0,
			"Artikelmetadaten wie Titel, Autor und Datum bearbeiten",
			"tt-ss",
			false];
	}

	function init($host) {
		$this->host = $host;

		$host->add_hook($host::HOOK_ARTICLE_BUTTON, $this);
		$host->add_hook($host::HOOK_QUERY_HEADLINES, $this);

		$m = new Db_Migrations();
		$m->initialize_for_plugin($this);
		$m->migrate();
	}

	function get_js() {
		return file_get_contents(__DIR__ . "/edit_metadata.js");
	}

	/**
	 * Button zum Bearbeiten der Metadaten.
	 * @param array<string, mixed> $line
	 * @return string
	 */
	function hook_article_button($line) {
		$id = (int) $line['id'];
		return "<i class='material-icons'
			onclick=\"Plugins.Edit_Metadata.edit(" . $id . ")\"
			style='cursor: pointer'
			title=\"" . __('Metadaten bearbeiten') . "\">edit</i>";
	}

	/**
	 * Überschreibungen auf Schlagzeilen anwenden.
	 * @param array<string, mixed> $row
	 * @param int $excerpt_length
	 * @return array<string, mixed>
	 */
	function hook_query_headlines($row, $excerpt_length = 0) {
		$ref_id = $row['id'] ?? 0;
		if (!$ref_id) return $row;

		$sth = $this->pdo->prepare("SELECT field_name, override_value
			FROM ttrss_plugin_metadata_overrides
			WHERE ref_id = ? AND owner_uid = ?");
		$sth->execute([$ref_id, $_SESSION['uid']]);

		while ($override = $sth->fetch()) {
			$field = $override['field_name'];
			$value = $override['override_value'];

			if (isset($row[$field]) && !empty($value)) {
				$row[$field] = $value;
			}
		}

		return $row;
	}

	/**
	 * Aktuelle Metadaten für einen Artikel abrufen (AJAX).
	 */
	function edit(): void {
		$ref_id = (int)clean($_REQUEST['id'] ?? 0);

		// Originaldaten aus der Datenbank holen
		$sth = $this->pdo->prepare("SELECT e.title, e.author,
			SUBSTRING_FOR_DATE(e.updated, 1, 16) AS updated
			FROM ttrss_entries e
			JOIN ttrss_user_entries ue ON ue.ref_id = e.id
			WHERE e.id = ? AND ue.owner_uid = ?");
		$sth->execute([$ref_id, $_SESSION['uid']]);

		$article = $sth->fetch();
		if (!$article) {
			print json_encode(['error' => 'Artikel nicht gefunden']);
			return;
		}

		// Vorhandene Überschreibungen laden
		$sth2 = $this->pdo->prepare("SELECT field_name, override_value
			FROM ttrss_plugin_metadata_overrides
			WHERE ref_id = ? AND owner_uid = ?");
		$sth2->execute([$ref_id, $_SESSION['uid']]);

		$overrides = [];
		while ($row = $sth2->fetch()) {
			$overrides[$row['field_name']] = $row['override_value'];
		}

		print json_encode([
			'ref_id' => $ref_id,
			'title' => $overrides['title'] ?? $article['title'],
			'author' => $overrides['author'] ?? $article['author'],
			'updated' => $article['updated'],
			'original_title' => $article['title'],
			'original_author' => $article['author']
		]);
	}

	/**
	 * Metadaten-Überschreibungen speichern (AJAX).
	 */
	function save_metadata(): void {
		$ref_id = (int)clean($_REQUEST['ref_id'] ?? 0);
		$fields = ['title', 'author'];

		if (!$ref_id) {
			print json_encode(['error' => 'Fehlende Artikel-ID']);
			return;
		}

		foreach ($fields as $field) {
			$value = clean($_REQUEST[$field] ?? '');

			// Vorhandene Überschreibung prüfen
			$sth = $this->pdo->prepare("SELECT id FROM ttrss_plugin_metadata_overrides
				WHERE ref_id = ? AND owner_uid = ? AND field_name = ?");
			$sth->execute([$ref_id, $_SESSION['uid'], $field]);

			if ($existing = $sth->fetch()) {
				if (!empty($value)) {
					$upd = $this->pdo->prepare("UPDATE ttrss_plugin_metadata_overrides
						SET override_value = ? WHERE id = ?");
					$upd->execute([$value, $existing['id']]);
				} else {
					$del = $this->pdo->prepare("DELETE FROM ttrss_plugin_metadata_overrides
						WHERE id = ?");
					$del->execute([$existing['id']]);
				}
			} elseif (!empty($value)) {
				$ins = $this->pdo->prepare("INSERT INTO ttrss_plugin_metadata_overrides
					(ref_id, owner_uid, field_name, override_value) VALUES (?, ?, ?, ?)");
				$ins->execute([$ref_id, $_SESSION['uid'], $field, $value]);
			}
		}

		print json_encode(['status' => 'ok']);
	}

	function api_version() {
		return 2;
	}
}
