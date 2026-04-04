/* global require, Plugins, PluginHost, xhr, App, Notify, fox, __ */

require(['dojo/_base/kernel', 'dojo/ready'], function (dojo, ready) {
	ready(function () {

		Plugins.Edit_Metadata = {

			edit: function (id) {
				xhr.json("backend.php", App.getPhArgs("edit_metadata", "edit", {
					id: id
				}), function (reply) {
					if (!reply || reply.error) {
						Notify.error(reply ? reply.error : __('Fehler beim Laden'));
						return;
					}

					const content = '<form dojoType="dijit.form.Form">' +
						'<input type="hidden" name="ref_id" value="' + reply.ref_id + '">' +
						'<fieldset><label>' + __('Titel:') + '</label>' +
						'<input dojoType="dijit.form.TextBox" name="title" ' +
						'style="width: 100%" value="' + App.escapeHtml(reply.title) + '">' +
						'<small>' + __('Original: ') + App.escapeHtml(reply.original_title) + '</small>' +
						'</fieldset>' +
						'<fieldset><label>' + __('Autor:') + '</label>' +
						'<input dojoType="dijit.form.TextBox" name="author" ' +
						'style="width: 100%" value="' + App.escapeHtml(reply.author || '') + '">' +
						'<small>' + __('Original: ') + App.escapeHtml(reply.original_author || '') + '</small>' +
						'</fieldset>' +
						'</form>';

					const dialog = new fox.SingleUseDialog({
						title: __('Metadaten bearbeiten'),
						execute: function () {
							if (this.validate()) {
								const values = this.attr('value');
								values.op = 'pluginhandler';
								values.plugin = 'edit_metadata';
								values.method = 'save_metadata';

								Notify.progress(__('Speichere...'), true);
								xhr.json("backend.php", values, function (result) {
									Notify.close();
									dialog.hide();

									if (result && result.status === 'ok') {
										Notify.info(__('Metadaten gespeichert'));
									} else {
										Notify.error(__('Fehler beim Speichern'));
									}
								});
							}
						},
						content: content
					});

					dialog.show();
				});
			}
		};
	});
});
