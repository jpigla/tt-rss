<?php
class Reading_Progress extends Plugin {

	/** @var PluginHost $host */
	private $host;

	function about() {
		return [1.0,
			"Speichert und zeigt den Lesefortschritt für Artikel an",
			"tt-ss",
			false];
	}

	function init($host) {
		$this->host = $host;

		$host->add_hook($host::HOOK_RENDER_ARTICLE_CDM, $this);
		$host->add_hook($host::HOOK_RENDER_ARTICLE, $this);

		$m = new Db_Migrations();
		$m->initialize_for_plugin($this);
		$m->migrate();
	}

	function get_js() {
		return file_get_contents(__DIR__ . "/reading_progress.js");
	}

	function get_css() {
		return file_get_contents(__DIR__ . "/reading_progress.css");
	}

	/**
	 * Fortschrittsbalken in CDM-Modus einfügen.
	 * @param array<string, mixed> $article
	 * @return array<string, mixed>
	 */
	function hook_render_article_cdm($article) {
		return $this->inject_progress_bar($article);
	}

	/**
	 * Fortschrittsbalken im Drei-Spalten-Modus einfügen.
	 * @param array<string, mixed> $article
	 * @return array<string, mixed>
	 */
	function hook_render_article($article) {
		return $this->inject_progress_bar($article);
	}

	/**
	 * @param array<string, mixed> $article
	 * @return array<string, mixed>
	 */
	private function inject_progress_bar(array $article): array {
		$id = $article['id'] ?? 0;
		if (!$id) return $article;

		$progress = 0;
		$sth = $this->pdo->prepare("SELECT progress_pct FROM ttrss_plugin_reading_progress
			WHERE ref_id = ? AND owner_uid = ?");
		$sth->execute([$id, $_SESSION['uid']]);

		if ($row = $sth->fetch()) {
			$progress = (float)$row['progress_pct'];
		}

		$bar = "<div class='rp-progress-container' data-article-id='$id'>
			<div class='rp-progress-bar' style='width: {$progress}%'></div>
		</div>";

		$article['content'] = $bar . $article['content'];

		return $article;
	}

	/**
	 * Lesefortschritt speichern (AJAX).
	 */
	function save_progress(): void {
		$ref_id = (int)clean($_REQUEST['ref_id'] ?? 0);
		$progress_pct = (float)clean($_REQUEST['progress_pct'] ?? 0);
		$last_position = (int)clean($_REQUEST['last_position'] ?? 0);

		if (!$ref_id) {
			print json_encode(['error' => 'Fehlende Artikel-ID']);
			return;
		}

		$progress_pct = max(0, min(100, $progress_pct));

		$sth = $this->pdo->prepare("SELECT id FROM ttrss_plugin_reading_progress
			WHERE ref_id = ? AND owner_uid = ?");
		$sth->execute([$ref_id, $_SESSION['uid']]);

		if ($sth->fetch()) {
			$upd = $this->pdo->prepare("UPDATE ttrss_plugin_reading_progress
				SET progress_pct = ?, last_position = ?, updated_at = NOW()
				WHERE ref_id = ? AND owner_uid = ?");
			$upd->execute([$progress_pct, $last_position, $ref_id, $_SESSION['uid']]);
		} else {
			$ins = $this->pdo->prepare("INSERT INTO ttrss_plugin_reading_progress
				(ref_id, owner_uid, progress_pct, last_position)
				VALUES (?, ?, ?, ?)");
			$ins->execute([$ref_id, $_SESSION['uid'], $progress_pct, $last_position]);
		}

		print json_encode(['status' => 'ok']);
	}

	/**
	 * Lesefortschritt abrufen (AJAX).
	 */
	function get_progress(): void {
		$ref_id = (int)clean($_REQUEST['ref_id'] ?? 0);

		$sth = $this->pdo->prepare("SELECT progress_pct, last_position
			FROM ttrss_plugin_reading_progress
			WHERE ref_id = ? AND owner_uid = ?");
		$sth->execute([$ref_id, $_SESSION['uid']]);

		if ($row = $sth->fetch()) {
			print json_encode($row);
		} else {
			print json_encode(['progress_pct' => 0, 'last_position' => 0]);
		}
	}

	function api_version() {
		return 2;
	}
}
