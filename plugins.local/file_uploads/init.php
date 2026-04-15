<?php
class File_Uploads extends Plugin {

	/** @var PluginHost $host */
	private $host;

	/** @var array<string> Erlaubte Dateiendungen */
	private array $allowed_extensions = ['txt', 'html', 'htm', 'pdf', 'docx'];

	function about() {
		return [1.0,
			"Dateien hochladen und als veröffentlichte Artikel anlegen",
			"tt-ss",
			false];
	}

	function init($host) {
		$this->host = $host;

		$host->add_hook($host::HOOK_PREFS_TAB, $this);
		$host->add_hook($host::HOOK_TOOLBAR_BUTTON, $this);

		$m = new Db_Migrations();
		$m->initialize_for_plugin($this);
		$m->migrate();
	}

	function get_js() {
		return file_get_contents(__DIR__ . "/file_uploads.js");
	}

	function get_css() {
		return file_get_contents(__DIR__ . "/file_uploads.css");
	}

	/**
	 * Toolbar-Button zum Hochladen.
	 */
	function hook_toolbar_button() {
		return "<a href='#' onclick='Plugins.File_Uploads.showUploadDialog(); return false'
			class='toolbar-btn' title=\"" . __('Datei hochladen') . "\">
			<i class='material-icons'>upload_file</i>
			<span class='toolbar-label'>" . __('Datei hochladen') . "</span></a>";
	}

	/**
	 * Einstellungsseite: Hochgeladene Dateien verwalten.
	 */
	function hook_prefs_tab($args) {
		if ($args != "prefPrefs") return;

		$sth = $this->pdo->prepare("SELECT * FROM ttrss_plugin_uploads
			WHERE owner_uid = ? ORDER BY uploaded_at DESC LIMIT 100");
		$sth->execute([$_SESSION['uid']]);

		?>
		<div dojoType="dijit.layout.AccordionPane"
			title="<i class='material-icons'>upload_file</i> <?= __('Datei-Uploads') ?>">

			<div style="margin-bottom: 10px">
				<button dojoType="dijit.form.Button"
					onclick="Plugins.File_Uploads.showUploadDialog()">
					<i class="material-icons">upload</i> <?= __('Datei hochladen') ?>
				</button>
			</div>

			<table width="100%">
				<tr class="title">
					<td><?= __('Dateiname') ?></td>
					<td><?= __('Typ') ?></td>
					<td><?= __('Größe') ?></td>
					<td><?= __('Hochgeladen') ?></td>
					<td></td>
				</tr>
				<?php while ($row = $sth->fetch()) { ?>
				<tr>
					<td><?= htmlspecialchars($row['filename']) ?></td>
					<td><?= htmlspecialchars($row['content_type']) ?></td>
					<td><?= $this->format_size((int)$row['file_size']) ?></td>
					<td><?= htmlspecialchars($row['uploaded_at']) ?></td>
					<td>
						<i class="material-icons" style="cursor:pointer"
							onclick="Plugins.File_Uploads.deleteUpload(<?= (int)$row['id'] ?>)"
							title="<?= __('Löschen') ?>">delete</i>
					</td>
				</tr>
				<?php } ?>
			</table>
		</div>
		<?php
	}

	/**
	 * Datei-Upload verarbeiten (AJAX).
	 */
	function upload(): void {
		if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
			print json_encode(['error' => __('Kein gültiger Upload')]);
			return;
		}

		$file = $_FILES['file'];
		$filename = basename($file['name']);
		$file_size = (int)$file['size'];
		$tmp_path = $file['tmp_name'];

		// Content-Type aus Dateiendung ableiten statt Client-Angabe zu vertrauen
		$ext_to_mime = [
			'txt' => 'text/plain', 'html' => 'text/html', 'htm' => 'text/html',
			'pdf' => 'application/pdf', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
		];
		$ext_lower = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
		$content_type = $ext_to_mime[$ext_lower] ?? 'application/octet-stream';

		// Dateiendung prüfen
		$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
		if (!in_array($ext, $this->allowed_extensions)) {
			print json_encode(['error' => sprintf(
				__('Dateityp .%s ist nicht erlaubt. Erlaubt: %s'),
				$ext,
				implode(', ', $this->allowed_extensions)
			)]);
			return;
		}

		// Größenlimit: 10 MB
		if ($file_size > 10 * 1024 * 1024) {
			print json_encode(['error' => __('Datei ist zu groß (maximal 10 MB)')]);
			return;
		}

		// Text extrahieren
		$extracted_text = $this->extract_text($tmp_path, $ext);

		// In Datenbank speichern
		$sth = $this->pdo->prepare("INSERT INTO ttrss_plugin_uploads
			(owner_uid, filename, content_type, extracted_text, file_size)
			VALUES (?, ?, ?, ?, ?)");
		$sth->execute([$_SESSION['uid'], $filename, $content_type, $extracted_text, $file_size]);

		$upload_id = $this->pdo->lastInsertId();

		// Als veröffentlichten Artikel anlegen
		if (!empty($extracted_text)) {
			$title = sprintf(__('Upload: %s'), $filename);
			$content = "<div class='fu-uploaded-content'>" . nl2br(htmlspecialchars($extracted_text)) . "</div>";

			if ($ext === 'html' || $ext === 'htm') {
				// HTML durch Sanitizer bereinigen (XSS-Schutz)
				$content = Sanitizer::sanitize($extracted_text, false, $_SESSION['uid']);
			}

			Article::_create_published_article($title, '', $content, '', $_SESSION['uid']);
		}

		// Temporäre Datei aufräumen
		@unlink($tmp_path);

		print json_encode([
			'id' => $upload_id,
			'status' => 'ok',
			'message' => sprintf(__('Datei "%s" hochgeladen und als Artikel veröffentlicht.'), $filename)
		]);
	}

	/**
	 * Text aus Datei extrahieren.
	 * @param string $path Dateipfad
	 * @param string $ext Dateiendung
	 * @return string
	 */
	private function extract_text(string $path, string $ext): string {
		switch ($ext) {
			case 'txt':
				return file_get_contents($path) ?: '';

			case 'html':
			case 'htm':
				return file_get_contents($path) ?: '';

			case 'pdf':
				return $this->extract_pdf_text($path);

			case 'docx':
				return $this->extract_docx_text($path);

			default:
				return '';
		}
	}

	/**
	 * Text aus PDF-Datei extrahieren via pdftotext.
	 * Verwendet escapeshellarg() zur sicheren Parameterübergabe.
	 * @param string $path Dateipfad
	 * @return string
	 */
	private function extract_pdf_text(string $path): string {
		$pdftotext_path = '';

		// Sicher prüfen ob pdftotext verfügbar ist
		$check = null;
		$output = [];
		$escaped = escapeshellarg('pdftotext');
		@exec("which " . $escaped . " 2>/dev/null", $output, $check);

		if ($check === 0 && !empty($output[0])) {
			$pdftotext_path = trim($output[0]);
		}

		if (empty($pdftotext_path)) {
			return sprintf(
				__('[PDF-Datei: Textextraktion nicht möglich - pdftotext nicht installiert. Dateiname: %s]'),
				basename($path)
			);
		}

		$escaped_path = escapeshellarg($path);
		$escaped_cmd = escapeshellarg($pdftotext_path);
		$result = [];
		$return_code = null;
		@exec("$escaped_cmd $escaped_path - 2>/dev/null", $result, $return_code);

		if ($return_code === 0) {
			return trim(implode("\n", $result));
		}

		return sprintf(
			__('[PDF-Textextraktion fehlgeschlagen für: %s]'),
			basename($path)
		);
	}

	/**
	 * Text aus DOCX-Datei extrahieren.
	 * @param string $path Dateipfad
	 * @return string
	 */
	private function extract_docx_text(string $path): string {
		$zip = new \ZipArchive();
		if ($zip->open($path) !== true) {
			return __('[DOCX konnte nicht geöffnet werden]');
		}

		$content = $zip->getFromName('word/document.xml');
		$zip->close();

		if (!$content) {
			return __('[Kein Inhalt im DOCX gefunden]');
		}

		// XML-Tags entfernen, um reinen Text zu erhalten
		$text = strip_tags($content);
		$text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');

		return trim($text);
	}

	/**
	 * Uploads auflisten (AJAX).
	 */
	function get_uploads(): void {
		$sth = $this->pdo->prepare("SELECT id, filename, content_type, file_size, uploaded_at
			FROM ttrss_plugin_uploads
			WHERE owner_uid = ?
			ORDER BY uploaded_at DESC");
		$sth->execute([$_SESSION['uid']]);

		$result = [];
		while ($row = $sth->fetch()) {
			$row['file_size_formatted'] = $this->format_size((int)$row['file_size']);
			$result[] = $row;
		}

		print json_encode($result);
	}

	/**
	 * Upload löschen (AJAX).
	 */
	function delete_upload(): void {
		$id = (int)clean($_REQUEST['id'] ?? 0);

		$sth = $this->pdo->prepare("DELETE FROM ttrss_plugin_uploads
			WHERE id = ? AND owner_uid = ?");
		$sth->execute([$id, $_SESSION['uid']]);

		print json_encode(['status' => 'ok']);
	}

	/**
	 * Dateigröße formatieren.
	 * @param int $bytes
	 * @return string
	 */
	private function format_size(int $bytes): string {
		if ($bytes < 1024) return $bytes . ' B';
		if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
		return round($bytes / 1048576, 1) . ' MB';
	}

	function api_version() {
		return 2;
	}
}
