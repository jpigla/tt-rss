<?php
class Archive_Articles extends Plugin {

	/** @var PluginHost $host */
	private $host;

	function about() {
		return [1.1,
			'Artikel dauerhaft archivieren (Purge-Schutz) und Feeds vom Purge ausnehmen',
			'tt-ss',
			false];
	}

	function init($host) {
		$this->host = $host;

		$host->add_hook($host::HOOK_ARTICLE_BUTTON, $this);
		$host->add_hook($host::HOOK_PREFS_TAB, $this);
	}

	function get_js() {
		return file_get_contents(__DIR__ . '/archive_articles.js');
	}

	function get_css() {
		return file_get_contents(__DIR__ . '/archive_articles.css');
	}

	// ─── Artikel-Button ──────────────────────────────────────────────────────

	/**
	 * Zeigt Archiv-/Dearchiv-Button rechts neben jedem Artikel.
	 * feed_id == 0 (FEED_ARCHIVED) bedeutet: Artikel ist bereits archiviert.
	 *
	 * @param array<string,mixed> $line
	 */
	function hook_article_button($line): string {
		$id        = (int) $line['id'];
		$archived  = ((int) $line['feed_id'] === 0);
		$icon      = $archived ? 'unarchive' : 'archive';
		$title     = $archived ? __('Dearchivieren') : __('Archivieren (Purge-Schutz)');
		$css_class = $archived ? 'aa-btn aa-btn--archived' : 'aa-btn';

		return "<i class='material-icons $css_class'
			data-article-id='$id'
			data-archived='" . ($archived ? '1' : '0') . "'
			onclick=\"ArchiveArticles.toggleFromButton($id, " . ($archived ? 'true' : 'false') . ")\"
			style='cursor:pointer'
			title=\"$title\">$icon</i>";
	}

	// ─── Backend-Aktionen ────────────────────────────────────────────────────

	/**
	 * Artikel archivieren: feed_id auf NULL setzen, orig_feed_id merken.
	 */
	function archiveSelection(): void {
		$ids = $this->_param_to_ids();
		if (!$ids) return;

		$ids_qmarks = arr_qmarks($ids);
		$pdo = Db::pdo();

		// Feed-Metadaten in ttrss_archived_feeds sichern (FK-Constraint)
		$sth = $pdo->prepare("SELECT DISTINCT feed_id FROM ttrss_user_entries
			WHERE ref_id IN ($ids_qmarks) AND owner_uid = ? AND feed_id IS NOT NULL");
		$sth->execute([...$ids, $_SESSION['uid']]);

		while ($row = $sth->fetch()) {
			$feed_id = (int) $row['feed_id'];

			$check = $pdo->prepare('SELECT id FROM ttrss_archived_feeds WHERE id = ?');
			$check->execute([$feed_id]);

			if (!$check->fetch()) {
				$fsth = $pdo->prepare('SELECT title, feed_url, site_url FROM ttrss_feeds WHERE id = ?');
				$fsth->execute([$feed_id]);

				if ($frow = $fsth->fetch()) {
					$isth = $pdo->prepare('INSERT INTO ttrss_archived_feeds
						(id, owner_uid, created, title, feed_url, site_url)
						VALUES (?, ?, NOW(), ?, ?, ?)');
					$isth->execute([$feed_id, $_SESSION['uid'], $frow['title'], $frow['feed_url'], $frow['site_url']]);
				}
			}
		}

		$sth = $pdo->prepare("UPDATE ttrss_user_entries SET
			orig_feed_id = COALESCE(orig_feed_id, feed_id),
			feed_id = NULL
			WHERE ref_id IN ($ids_qmarks) AND owner_uid = ? AND feed_id IS NOT NULL");
		$sth->execute([...$ids, $_SESSION['uid']]);

		print json_encode(['message' => 'UPDATE_COUNTERS']);
	}

	/**
	 * Artikel dearchivieren: feed_id aus orig_feed_id wiederherstellen.
	 */
	function unarchiveSelection(): void {
		$ids = $this->_param_to_ids();
		if (!$ids) return;

		$ids_qmarks = arr_qmarks($ids);
		$pdo = Db::pdo();

		$sth = $pdo->prepare("UPDATE ttrss_user_entries SET
			feed_id = ttrss_user_entries.orig_feed_id,
			orig_feed_id = NULL
			FROM ttrss_feeds
			WHERE ttrss_user_entries.ref_id IN ($ids_qmarks)
			AND ttrss_user_entries.owner_uid = ?
			AND ttrss_user_entries.feed_id IS NULL
			AND ttrss_user_entries.orig_feed_id = ttrss_feeds.id
			AND ttrss_feeds.owner_uid = ttrss_user_entries.owner_uid");
		$sth->execute([...$ids, $_SESSION['uid']]);

		// Aufräumen wenn Original-Feed gelöscht
		$sth2 = $pdo->prepare("UPDATE ttrss_user_entries SET
			orig_feed_id = NULL
			WHERE ref_id IN ($ids_qmarks) AND owner_uid = ? AND feed_id IS NULL AND orig_feed_id IS NOT NULL
			AND orig_feed_id NOT IN (SELECT id FROM ttrss_feeds WHERE owner_uid = ?)");
		$sth2->execute([...$ids, $_SESSION['uid'], $_SESSION['uid']]);

		print json_encode(['message' => 'UPDATE_COUNTERS']);
	}

	/**
	 * Feed-Purge-Intervall setzen (z.B. -1 = nie purgen).
	 */
	function setFeedPurge(): void {
		$feed_id  = (int) clean($_POST['feed_id'] ?? 0);
		$interval_raw = clean($_POST['purge_interval'] ?? '');
		$interval = ($interval_raw === '' || is_null($interval_raw)) ? null : (int) $interval_raw;

		if (!$feed_id) return;

		$pdo = Db::pdo();
		$sth = $pdo->prepare('UPDATE ttrss_feeds SET purge_interval = ? WHERE id = ? AND owner_uid = ?');
		$sth->execute([$interval, $feed_id, $_SESSION['uid']]);

		if (is_null($interval))
			echo __('Purge-Intervall auf Erben (Kategorie/Standard) gesetzt.');
		elseif ($interval === -1)
			echo __('Feed wird nie gepurged.');
		else
			echo sprintf(__('Purge-Intervall auf %d Tage gesetzt.'), $interval);
	}

	// ─── Prefs-Tab ───────────────────────────────────────────────────────────

	function hook_prefs_tab($args): void {
		if ($args !== 'prefPrefs') return;

		$pdo = Db::pdo();

		// Feeds mit ihren Purge-Einstellungen laden
		$sth = $pdo->prepare(
			'SELECT id, title, purge_interval FROM ttrss_feeds WHERE owner_uid = ? ORDER BY title'
		);
		$sth->execute([$_SESSION['uid']]);
		$feeds = $sth->fetchAll();

		// Anzahl archivierter Artikel
		$sth2 = $pdo->prepare(
			'SELECT COUNT(*) FROM ttrss_user_entries WHERE owner_uid = ? AND feed_id IS NULL'
		);
		$sth2->execute([$_SESSION['uid']]);
		$archived_count = (int) $sth2->fetchColumn();

		$never_purge_ids = array_column(
			array_filter($feeds, fn($f) => (int) $f['purge_interval'] === -1),
			'id'
		);

		?>
		<div dojoType="dijit.layout.AccordionPane"
			title="<i class='material-icons'>inventory_2</i> <?= __('Archiv & Purge-Schutz') ?>">

			<p><?= sprintf(
				__('%d Artikel dauerhaft archiviert (außerhalb des Purge-Zyklus).'),
				$archived_count
			) ?></p>

			<p style="color: var(--hint-color); font-size: 0.9em; margin-bottom: 16px">
				<?= __('Archivierte Artikel haben <code>feed_id = NULL</code> und werden vom Purge nie erfasst. '
				. 'Starred-Artikel (⭐) sind ebenfalls geschützt (<code>marked = true</code>). '
				. 'Feeds mit Intervall „Nie" werden nie geleert.') ?>
			</p>

			<hr/>
			<h3 style="margin: 12px 0 8px"><?= __('Purge-Einstellungen pro Feed') ?></h3>

			<table class="aa-purge-table" style="width:100%; border-collapse:collapse">
				<thead>
					<tr>
						<th style="text-align:left; padding: 4px 8px"><?= __('Feed') ?></th>
						<th style="text-align:left; padding: 4px 8px; width:200px"><?= __('Purge-Intervall') ?></th>
						<th style="padding: 4px 8px; width:120px"></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ($feeds as $feed):
					$fid      = (int) $feed['id'];
					$interval = (int) $feed['purge_interval'];
					$is_never = $interval === -1;
					$label    = is_null($interval) ? __('Erbt') : ($is_never ? __('Nie') : ($interval === 0 ? __('Global-Standard') : sprintf(__('%d Tage'), $interval)));
				?>
				<tr class="<?= $is_never ? 'aa-never-purge' : '' ?>">
					<td style="padding: 4px 8px"><?= htmlspecialchars($feed['title']) ?></td>
					<td style="padding: 4px 8px"><?= $label ?></td>
					<td style="padding: 4px 8px; text-align:right">
						<?php if (!$is_never): ?>
						<button dojoType="dijit.form.Button" class="alt-info"
							onclick="ArchiveArticles.setNeverPurge(<?= $fid ?>, this); return false">
							<?= __('Nie purgen') ?>
						</button>
						<?php else: ?>
						<button dojoType="dijit.form.Button"
							onclick="ArchiveArticles.resetPurge(<?= $fid ?>, this); return false">
							<?= __('Standard') ?>
						</button>
						<?php endif; ?>
					</td>
				</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

		</div>
		<?php
	}

	// ─── Hilfsmethoden ───────────────────────────────────────────────────────

	/** @return array<int> */
	private function _param_to_ids(): array {
		$raw = clean($_REQUEST['ids'] ?? '');
		if (empty($raw)) return [];
		return array_map('intval', array_filter(explode(',', $raw), 'strlen'));
	}

	function api_version() {
		return 2;
	}
}
