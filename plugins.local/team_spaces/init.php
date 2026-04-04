<?php
class Team_Spaces extends Plugin {

	/** @var PluginHost $host */
	private $host;

	function about() {
		return [1.0,
			"Teams erstellen, Artikel teilen und gemeinsam diskutieren",
			"tt-ss",
			false];
	}

	function init($host) {
		$this->host = $host;

		$host->add_hook($host::HOOK_PREFS_TAB, $this);
		$host->add_hook($host::HOOK_ARTICLE_BUTTON, $this);

		$m = new Db_Migrations();
		$m->initialize_for_plugin($this);
		$m->migrate();
	}

	function get_js() {
		return file_get_contents(__DIR__ . "/team_spaces.js");
	}

	function get_css() {
		return file_get_contents(__DIR__ . "/team_spaces.css");
	}

	/**
	 * Artikelbutton zum Teilen im Team.
	 * @param array<string, mixed> $line
	 * @return string
	 */
	function hook_article_button($line) {
		$id = $line['id'];
		return "<i class='material-icons'
			onclick=\"Plugins.Team_Spaces.shareDialog($id)\"
			style='cursor: pointer'
			title=\"" . __('Im Team teilen') . "\">group</i>";
	}

	/**
	 * Einstellungsseite: Teams verwalten.
	 */
	function hook_prefs_tab($args) {
		if ($args != "prefPrefs") return;

		// Teams des Benutzers laden
		$sth = $this->pdo->prepare("SELECT t.*,
			(SELECT COUNT(*) FROM ttrss_plugin_team_members WHERE team_id = t.id) AS member_count,
			(SELECT COUNT(*) FROM ttrss_plugin_team_shares WHERE team_id = t.id) AS share_count
			FROM ttrss_plugin_teams t
			JOIN ttrss_plugin_team_members tm ON tm.team_id = t.id
			WHERE tm.user_id = ?
			ORDER BY t.name");
		$sth->execute([$_SESSION['uid']]);

		?>
		<div dojoType="dijit.layout.AccordionPane"
			title="<i class='material-icons'>group</i> <?= __('Team Spaces') ?>">

			<div style="margin-bottom: 10px">
				<button dojoType="dijit.form.Button"
					onclick="Plugins.Team_Spaces.createTeam()">
					<i class="material-icons">add</i> <?= __('Neues Team erstellen') ?>
				</button>
			</div>

			<?php while ($team = $sth->fetch()) { ?>
			<div class="ts-team-card">
				<div class="ts-team-header">
					<h3><?= htmlspecialchars($team['name']) ?></h3>
					<span class="ts-meta">
						<?= sprintf(__('%d Mitglieder'), (int)$team['member_count']) ?> |
						<?= sprintf(__('%d geteilte Artikel'), (int)$team['share_count']) ?>
					</span>
				</div>

				<div class="ts-team-actions">
					<button dojoType="dijit.form.Button"
						onclick="Plugins.Team_Spaces.inviteMember(<?= (int)$team['id'] ?>)">
						<i class="material-icons">person_add</i> <?= __('Mitglied einladen') ?>
					</button>
					<button dojoType="dijit.form.Button"
						onclick="Plugins.Team_Spaces.viewShared(<?= (int)$team['id'] ?>)">
						<i class="material-icons">article</i> <?= __('Geteilte Artikel') ?>
					</button>
					<?php if ((int)$team['owner_uid'] === (int)$_SESSION['uid']) { ?>
					<button dojoType="dijit.form.Button" class="alt-danger"
						onclick="Plugins.Team_Spaces.deleteTeam(<?= (int)$team['id'] ?>)">
						<i class="material-icons">delete</i> <?= __('Löschen') ?>
					</button>
					<?php } ?>
				</div>
			</div>
			<?php } ?>
		</div>
		<?php
	}

	/**
	 * Neues Team erstellen (AJAX).
	 */
	function create_team(): void {
		$name = clean($_REQUEST['name'] ?? '');

		if (empty($name)) {
			print json_encode(['error' => __('Teamname ist erforderlich')]);
			return;
		}

		$sth = $this->pdo->prepare("INSERT INTO ttrss_plugin_teams
			(name, owner_uid) VALUES (?, ?)");
		$sth->execute([$name, $_SESSION['uid']]);

		$team_id = $this->pdo->lastInsertId();

		// Ersteller als Admin-Mitglied hinzufügen
		$sth2 = $this->pdo->prepare("INSERT INTO ttrss_plugin_team_members
			(team_id, user_id, role) VALUES (?, ?, 'admin')");
		$sth2->execute([$team_id, $_SESSION['uid']]);

		print json_encode(['id' => $team_id, 'status' => 'ok']);
	}

	/**
	 * Mitglied zum Team einladen (AJAX).
	 */
	function invite_member(): void {
		$team_id = (int)clean($_REQUEST['team_id'] ?? 0);
		$login = clean($_REQUEST['login'] ?? '');

		if (!$team_id || empty($login)) {
			print json_encode(['error' => __('Team-ID und Benutzername sind erforderlich')]);
			return;
		}

		// Prüfen ob der aufrufende Benutzer Mitglied des Teams ist
		$sth = $this->pdo->prepare("SELECT role FROM ttrss_plugin_team_members
			WHERE team_id = ? AND user_id = ?");
		$sth->execute([$team_id, $_SESSION['uid']]);
		$membership = $sth->fetch();

		if (!$membership) {
			print json_encode(['error' => __('Kein Zugriff auf dieses Team')]);
			return;
		}

		// Benutzer anhand des Login-Namens finden
		$sth2 = $this->pdo->prepare("SELECT id FROM ttrss_users WHERE login = ?");
		$sth2->execute([$login]);
		$user = $sth2->fetch();

		if (!$user) {
			print json_encode(['error' => __('Benutzer nicht gefunden')]);
			return;
		}

		// Prüfen ob bereits Mitglied
		$sth3 = $this->pdo->prepare("SELECT id FROM ttrss_plugin_team_members
			WHERE team_id = ? AND user_id = ?");
		$sth3->execute([$team_id, $user['id']]);

		if ($sth3->fetch()) {
			print json_encode(['error' => __('Benutzer ist bereits Mitglied')]);
			return;
		}

		$ins = $this->pdo->prepare("INSERT INTO ttrss_plugin_team_members
			(team_id, user_id, role) VALUES (?, ?, 'member')");
		$ins->execute([$team_id, $user['id']]);

		print json_encode(['status' => 'ok']);
	}

	/**
	 * Mitglied aus dem Team entfernen (AJAX).
	 */
	function remove_member(): void {
		$team_id = (int)clean($_REQUEST['team_id'] ?? 0);
		$user_id = (int)clean($_REQUEST['user_id'] ?? 0);

		// Prüfen ob der aufrufende Benutzer Admin ist
		$sth = $this->pdo->prepare("SELECT role FROM ttrss_plugin_team_members
			WHERE team_id = ? AND user_id = ?");
		$sth->execute([$team_id, $_SESSION['uid']]);
		$membership = $sth->fetch();

		if (!$membership || $membership['role'] !== 'admin') {
			print json_encode(['error' => __('Nur Admins können Mitglieder entfernen')]);
			return;
		}

		$del = $this->pdo->prepare("DELETE FROM ttrss_plugin_team_members
			WHERE team_id = ? AND user_id = ?");
		$del->execute([$team_id, $user_id]);

		print json_encode(['status' => 'ok']);
	}

	/**
	 * Artikel mit Team teilen (AJAX).
	 */
	function share_article(): void {
		$team_id = (int)clean($_REQUEST['team_id'] ?? 0);
		$ref_id = (int)clean($_REQUEST['ref_id'] ?? 0);
		$comment = clean($_REQUEST['comment'] ?? '');

		if (!$team_id || !$ref_id) {
			print json_encode(['error' => __('Fehlende Daten')]);
			return;
		}

		// Prüfen ob Mitglied
		$sth = $this->pdo->prepare("SELECT id FROM ttrss_plugin_team_members
			WHERE team_id = ? AND user_id = ?");
		$sth->execute([$team_id, $_SESSION['uid']]);

		if (!$sth->fetch()) {
			print json_encode(['error' => __('Kein Zugriff auf dieses Team')]);
			return;
		}

		$ins = $this->pdo->prepare("INSERT INTO ttrss_plugin_team_shares
			(team_id, user_id, ref_id, comment) VALUES (?, ?, ?, ?)");
		$ins->execute([$team_id, $_SESSION['uid'], $ref_id, $comment]);

		print json_encode(['status' => 'ok']);
	}

	/**
	 * Geteilte Artikel eines Teams abrufen (AJAX).
	 */
	function get_shared(): void {
		$team_id = (int)clean($_REQUEST['team_id'] ?? 0);

		// Prüfen ob Mitglied
		$sth = $this->pdo->prepare("SELECT id FROM ttrss_plugin_team_members
			WHERE team_id = ? AND user_id = ?");
		$sth->execute([$team_id, $_SESSION['uid']]);

		if (!$sth->fetch()) {
			print json_encode(['error' => __('Kein Zugriff auf dieses Team')]);
			return;
		}

		$sth2 = $this->pdo->prepare("SELECT ts.*, e.title, e.link,
			u.login AS shared_by
			FROM ttrss_plugin_team_shares ts
			JOIN ttrss_entries e ON e.id = ts.ref_id
			JOIN ttrss_users u ON u.id = ts.user_id
			WHERE ts.team_id = ?
			ORDER BY ts.shared_at DESC
			LIMIT 100");
		$sth2->execute([$team_id]);

		$result = [];
		while ($row = $sth2->fetch()) {
			$result[] = $row;
		}

		print json_encode($result);
	}

	/**
	 * Teams des Benutzers abrufen (AJAX).
	 */
	function get_teams(): void {
		$sth = $this->pdo->prepare("SELECT t.id, t.name
			FROM ttrss_plugin_teams t
			JOIN ttrss_plugin_team_members tm ON tm.team_id = t.id
			WHERE tm.user_id = ?
			ORDER BY t.name");
		$sth->execute([$_SESSION['uid']]);

		$result = [];
		while ($row = $sth->fetch()) {
			$result[] = $row;
		}

		print json_encode($result);
	}

	/**
	 * Team löschen (AJAX). Nur der Eigentümer kann löschen.
	 */
	function delete_team(): void {
		$team_id = (int)clean($_REQUEST['team_id'] ?? 0);

		// Prüfen ob der aufrufende Benutzer der Eigentümer ist
		$sth = $this->pdo->prepare("SELECT id FROM ttrss_plugin_teams
			WHERE id = ? AND owner_uid = ?");
		$sth->execute([$team_id, $_SESSION['uid']]);

		if (!$sth->fetch()) {
			print json_encode(['error' => __('Nur der Eigentümer kann das Team löschen')]);
			return;
		}

		// Team löschen (CASCADE entfernt Mitglieder und geteilte Artikel)
		$del = $this->pdo->prepare("DELETE FROM ttrss_plugin_teams
			WHERE id = ? AND owner_uid = ?");
		$del->execute([$team_id, $_SESSION['uid']]);

		print json_encode(['status' => 'ok']);
	}

	function api_version() {
		return 2;
	}
}
