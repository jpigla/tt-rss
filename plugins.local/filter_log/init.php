<?php
class Filter_Log extends Plugin {

	/** @var PluginHost $host */
	private $host;

	/** @var int Maximale Anzahl Log-Einträge pro Benutzer */
	private int $max_entries = 1000;

	function about() {
		return [1.0,
			"Protokolliert alle Filter-Aktionen für Transparenz und Debugging",
			"tt-ss",
			false];
	}

	function init($host) {
		$this->host = $host;

		$host->add_hook($host::HOOK_FILTER_TRIGGERED, $this);
		$host->add_hook($host::HOOK_PREFS_TAB, $this);
		$host->add_hook($host::HOOK_HOUSE_KEEPING, $this);

		$m = new Db_Migrations();
		$m->initialize_for_plugin($this);
		$m->migrate();
	}

	/**
	 * @param int $feed_id
	 * @param int $owner_uid
	 * @param array<string, mixed> $article
	 * @param array<string, mixed> $matched_filters
	 * @param array<string, string|bool|int> $matched_rules
	 * @param array<int, array{type: string, param: string}> $article_filter_actions
	 */
	function hook_filter_triggered($feed_id, $owner_uid, $article, $matched_filters, $matched_rules, $article_filter_actions): void {

		$article_title = $article['title'] ?? '';
		$article_link = $article['link'] ?? '';

		// Filter-Titel aus matched_filters extrahieren
		$filter_titles = [];
		$filter_ids = [];
		foreach ($matched_filters as $filter) {
			$filter_titles[] = $filter['title'] ?? ('Filter #' . ($filter['id'] ?? '?'));
			$filter_ids[] = $filter['id'] ?? 0;
		}

		// Aktionen als lesbaren Text zusammenfassen
		$action_descriptions = [];
		foreach ($article_filter_actions as $action) {
			$desc = $action['type'] ?? 'unbekannt';
			if (!empty($action['param'])) {
				$desc .= ': ' . $action['param'];
			}
			$action_descriptions[] = $desc;
		}

		// Regeln als lesbaren Text
		$rule_descriptions = [];
		foreach ($matched_rules as $rule) {
			if (is_array($rule)) {
				$rule_descriptions[] = ($rule['reg_exp'] ?? '') . ' (' . ($rule['type_name'] ?? '') . ')';
			}
		}

		$sth = $this->pdo->prepare("INSERT INTO ttrss_plugin_filter_log
			(owner_uid, feed_id, article_title, article_link, filter_id, filter_title, matched_rules, actions)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?)");

		$sth->execute([
			$owner_uid,
			$feed_id,
			mb_substr($article_title, 0, 500),
			mb_substr($article_link, 0, 1000),
			$filter_ids[0] ?? null,
			implode(', ', $filter_titles),
			implode('; ', $rule_descriptions),
			implode(', ', $action_descriptions)
		]);
	}

	/**
	 * Alte Log-Einträge aufräumen.
	 */
	function hook_house_keeping(): void {
		// Einträge älter als 30 Tage löschen
		$this->pdo->query("DELETE FROM ttrss_plugin_filter_log
			WHERE triggered_at < NOW() - INTERVAL '30 days'");

		// Pro Benutzer maximal $max_entries behalten
		$sth = $this->pdo->prepare("DELETE FROM ttrss_plugin_filter_log
			WHERE id IN (
				SELECT fl.id FROM ttrss_plugin_filter_log fl
				WHERE fl.owner_uid IN (
					SELECT DISTINCT owner_uid FROM ttrss_plugin_filter_log
				)
				AND fl.id NOT IN (
					SELECT fl2.id FROM ttrss_plugin_filter_log fl2
					WHERE fl2.owner_uid = fl.owner_uid
					ORDER BY fl2.triggered_at DESC
					LIMIT ?
				)
			)");
		$sth->execute([$this->max_entries]);
	}

	function hook_prefs_tab($args) {
		if ($args != "prefFeeds") return;

		$owner_uid = $_SESSION['uid'];

		// Statistiken
		$sth = $this->pdo->prepare("SELECT COUNT(*) AS cnt FROM ttrss_plugin_filter_log WHERE owner_uid = ?");
		$sth->execute([$owner_uid]);
		$total = $sth->fetch()['cnt'] ?? 0;

		$sth = $this->pdo->prepare("SELECT COUNT(*) AS cnt FROM ttrss_plugin_filter_log
			WHERE owner_uid = ? AND triggered_at >= NOW() - INTERVAL '24 hours'");
		$sth->execute([$owner_uid]);
		$today = $sth->fetch()['cnt'] ?? 0;

		// Letzte Log-Einträge
		$sth = $this->pdo->prepare("SELECT * FROM ttrss_plugin_filter_log
			WHERE owner_uid = ?
			ORDER BY triggered_at DESC
			LIMIT 50");
		$sth->execute([$owner_uid]);

		?>
		<div dojoType="dijit.layout.AccordionPane"
			title="<i class='material-icons'>receipt_long</i> <?= __('Filter-Log') ?>">

			<div class="filter-log-stats">
				<span><strong><?= $today ?></strong> <?= __('Filter-Treffer heute') ?></span>
				&nbsp;&mdash;&nbsp;
				<span><strong><?= $total ?></strong> <?= __('Einträge gesamt') ?></span>

				<form dojoType="dijit.form.Form" style="display: inline; margin-left: 16px;">
					<?= \Controls\pluginhandler_tags($this, "clearlog") ?>
					<button dojoType="dijit.form.Button" type="submit" class="alt-danger">
						<i class="material-icons">delete_sweep</i> <?= __('Log leeren') ?>
					</button>
				</form>
			</div>

			<table class="prefFilterLog" width="100%">
				<thead>
					<tr>
						<th><?= __('Zeitpunkt') ?></th>
						<th><?= __('Artikel') ?></th>
						<th><?= __('Filter') ?></th>
						<th><?= __('Regeln') ?></th>
						<th><?= __('Aktionen') ?></th>
					</tr>
				</thead>
				<tbody>
					<?php while ($row = $sth->fetch()) { ?>
					<tr>
						<td class="text-muted" style="white-space: nowrap">
							<?= TimeHelper::smart_date_time(strtotime($row['triggered_at'])) ?>
						</td>
						<td>
							<?php if (!empty($row['article_link'])) { ?>
								<a href="<?= htmlspecialchars($row['article_link']) ?>"
								   target="_blank" rel="noopener noreferrer">
									<?= htmlspecialchars(mb_substr($row['article_title'], 0, 80)) ?>
								</a>
							<?php } else { ?>
								<?= htmlspecialchars(mb_substr($row['article_title'], 0, 80)) ?>
							<?php } ?>
						</td>
						<td><?= htmlspecialchars($row['filter_title']) ?></td>
						<td class="text-muted"><?= htmlspecialchars(mb_substr($row['matched_rules'], 0, 100)) ?></td>
						<td><code><?= htmlspecialchars($row['actions']) ?></code></td>
					</tr>
					<?php } ?>
				</tbody>
			</table>

			<?php if ($total == 0) { ?>
				<p class="text-muted text-center"><?= __('Noch keine Filter-Aktivität protokolliert.') ?></p>
			<?php } ?>
		</div>
		<?php
	}

	function clearlog(): void {
		$sth = $this->pdo->prepare("DELETE FROM ttrss_plugin_filter_log WHERE owner_uid = ?");
		$sth->execute([$_SESSION['uid']]);
		echo __("Filter-Log geleert.");
	}

	function get_css() {
		return file_get_contents(__DIR__ . "/filter_log.css");
	}

	function api_version() {
		return 2;
	}
}
