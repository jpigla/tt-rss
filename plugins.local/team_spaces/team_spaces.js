/* global require, Plugins, PluginHost, xhr, App, Notify, fox, __ */

require(['dojo/_base/kernel', 'dojo/ready'], function (dojo, ready) {
	ready(function () {

		Plugins.Team_Spaces = {

			shareDialog: function (articleId) {
				xhr.json("backend.php", App.getPhArgs("team_spaces", "get_teams"), function (teams) {
					if (!teams || teams.length === 0) {
						Notify.info(__('Du bist noch keinem Team beigetreten. Erstelle zuerst ein Team in den Einstellungen.'));
						return;
					}

					let options = '';
					teams.forEach(function (team) {
						options += '<option value="' + team.id + '">' +
							App.escapeHtml(team.name) + '</option>';
					});

					const content =
						'<fieldset><label>' + __('Team:') + '</label>' +
						'<select name="team_id" dojoType="dijit.form.Select">' +
						options + '</select></fieldset>' +
						'<fieldset><label>' + __('Kommentar (optional):') + '</label>' +
						'<textarea dojoType="dijit.form.SimpleTextarea" name="comment" ' +
						'style="width: 100%; height: 60px"></textarea></fieldset>' +
						'<footer class="text-center">' +
						'<button dojoType="dijit.form.Button" type="submit" class="alt-primary">' +
						__('Teilen') + '</button> ' +
						'<button dojoType="dijit.form.Button" onclick="App.dialogOf(this).hide()">' +
						__('Abbrechen') + '</button></footer>';

					const dialog = new fox.SingleUseDialog({
						title: __('Artikel im Team teilen'),
						execute: function () {
							if (this.validate()) {
								const values = this.attr('value');

								xhr.json("backend.php", App.getPhArgs("team_spaces", "share_article", {
									team_id: values.team_id,
									ref_id: articleId,
									comment: values.comment || ''
								}), function (reply) {
									dialog.hide();
									if (reply && reply.status === 'ok') {
										Notify.info(__('Artikel geteilt'));
									} else {
										Notify.error(reply ? reply.error : __('Fehler'));
									}
								});
							}
						},
						content: content
					});

					dialog.show();
				});
			},

			createTeam: function () {
				const name = prompt(__('Name des neuen Teams:'));
				if (!name) return;

				xhr.json("backend.php", App.getPhArgs("team_spaces", "create_team", {
					name: name
				}), function (reply) {
					if (reply && reply.status === 'ok') {
						Notify.info(__('Team erstellt'));
						window.location.reload();
					} else {
						Notify.error(reply ? reply.error : __('Fehler'));
					}
				});
			},

			inviteMember: function (teamId) {
				const login = prompt(__('Benutzername des einzuladenden Mitglieds:'));
				if (!login) return;

				xhr.json("backend.php", App.getPhArgs("team_spaces", "invite_member", {
					team_id: teamId,
					login: login
				}), function (reply) {
					if (reply && reply.status === 'ok') {
						Notify.info(__('Mitglied eingeladen'));
						window.location.reload();
					} else {
						Notify.error(reply ? reply.error : __('Fehler'));
					}
				});
			},

			viewShared: function (teamId) {
				xhr.json("backend.php", App.getPhArgs("team_spaces", "get_shared", {
					team_id: teamId
				}), function (reply) {
					if (!reply) return;

					let content = '';
					if (reply.length === 0) {
						content = '<p>' + __('Noch keine Artikel geteilt.') + '</p>';
					} else {
						content = '<table width="100%"><tr class="title">' +
							'<td>' + __('Titel') + '</td>' +
							'<td>' + __('Geteilt von') + '</td>' +
							'<td>' + __('Kommentar') + '</td>' +
							'<td>' + __('Datum') + '</td></tr>';

						reply.forEach(function (share) {
							content += '<tr>' +
								'<td><a href="' + App.escapeHtml(share.link || '#') +
								'" target="_blank" rel="noopener">' +
								App.escapeHtml(share.title) + '</a></td>' +
								'<td>' + App.escapeHtml(share.shared_by) + '</td>' +
								'<td>' + App.escapeHtml(share.comment || '') + '</td>' +
								'<td>' + App.escapeHtml(share.shared_at) + '</td></tr>';
						});
						content += '</table>';
					}

					content += '<footer class="text-center">' +
						'<button dojoType="dijit.form.Button" type="submit">' +
						__('Schließen') + '</button></footer>';

					const dialog = new fox.SingleUseDialog({
						title: __('Geteilte Artikel'),
						content: content
					});
					dialog.show();
				});
			},

			deleteTeam: function (teamId) {
				if (!confirm(__('Team und alle geteilten Artikel wirklich löschen?'))) return;

				xhr.json("backend.php", App.getPhArgs("team_spaces", "delete_team", {
					team_id: teamId
				}), function (reply) {
					if (reply && reply.status === 'ok') {
						Notify.info(__('Team gelöscht'));
						window.location.reload();
					}
				});
			}
		};
	});
});
