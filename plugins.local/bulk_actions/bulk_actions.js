/* global Plugins, App, Notify, xhr, dojo, dijit, Headlines */
/* Note: innerHTML usage follows TT-RSS plugin conventions; all user data is escaped via App.escapeHtml() */

if (typeof Plugins === "undefined") window.Plugins = {};

Plugins.Bulk_Actions = {

	/**
	 * Tags zu allen ausgewählten Artikeln hinzufügen.
	 */
	bulkTag: function() {
		const ids = Headlines.getSelected();
		if (ids.length === 0) {
			Notify.error("Keine Artikel ausgewählt.");
			return;
		}

		const tags = prompt("Tags eingeben (kommagetrennt):");
		if (!tags) return;

		Notify.progress("Tags werden zugewiesen...");

		xhr.json("backend.php", {
			op: "pluginhandler",
			plugin: "bulk_actions",
			method: "bulk_tag",
			tags: tags,
			ids: JSON.stringify(ids)
		}, (reply) => {
			if (reply.status === "ok") {
				Notify.info(`${reply.tagged} Artikel getaggt.`);
			} else {
				Notify.error(reply.message || "Fehler beim Taggen.");
			}
		});
	},

	/**
	 * Label zu allen ausgewählten Artikeln zuweisen.
	 */
	bulkLabel: function() {
		const ids = Headlines.getSelected();
		if (ids.length === 0) {
			Notify.error("Keine Artikel ausgewählt.");
			return;
		}

		// Labels laden und Dialog anzeigen
		xhr.json("backend.php", {
			op: "pluginhandler",
			plugin: "bulk_actions",
			method: "get_labels"
		}, (labels) => {
			if (labels.length === 0) {
				Notify.error("Keine Labels vorhanden.");
				return;
			}

			let optionsHtml = "";
			for (const label of labels) {
				optionsHtml += `<option value="${parseInt(label.id, 10)}">${App.escapeHtml(label.caption)}</option>`;
			}

			const dialog = new dijit.Dialog({
				title: "Label zuweisen",
				style: "width: 350px",
				content: `
					<div>
						<p>${ids.length} Artikel ausgewählt</p>
						<select id="ba-label-select" dojoType="dijit.form.Select" style="width: 250px">
							${optionsHtml}
						</select>
						<br/><br/>
						<button dojoType="dijit.form.Button" onclick="Plugins.Bulk_Actions._applyLabel()">Zuweisen</button>
						<button dojoType="dijit.form.Button" onclick="Plugins.Bulk_Actions._closeDialogs()">Abbrechen</button>
					</div>
				`
			});

			Plugins.Bulk_Actions._pendingIds = ids;
			dialog.show();
		});
	},

	/** @private */
	_pendingIds: [],

	/** @private */
	_applyLabel: function() {
		const select = dijit.byId("ba-label-select");
		if (!select) return;

		const labelId = select.get("value");
		const ids = Plugins.Bulk_Actions._pendingIds;

		Plugins.Bulk_Actions._closeDialogs();
		Notify.progress("Labels werden zugewiesen...");

		xhr.json("backend.php", {
			op: "pluginhandler",
			plugin: "bulk_actions",
			method: "bulk_label",
			label_id: labelId,
			ids: JSON.stringify(ids)
		}, (reply) => {
			if (reply.status === "ok") {
				Notify.info(`Label an ${reply.labeled} Artikel zugewiesen.`);
			} else {
				Notify.error(reply.message || "Fehler beim Zuweisen.");
			}
		});
	},

	/**
	 * Ausgewählte Artikel als gelesen markieren und ausblenden.
	 */
	markReadAndHide: function() {
		const ids = Headlines.getSelected();
		if (ids.length === 0) {
			Notify.error("Keine Artikel ausgewählt.");
			return;
		}

		Headlines.selectionToggleUnread({ids: ids, cmode: 0});
		Notify.info(`${ids.length} Artikel als gelesen markiert.`);
	},

	/** @private */
	_closeDialogs: function() {
		dijit.registry.filter((w) => w.declaredClass === "dijit.Dialog").forEach((d) => d.hide());
	}
};
