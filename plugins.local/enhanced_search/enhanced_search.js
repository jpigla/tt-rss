/* global Plugins, App, Notify, xhr, dojo, dijit, Headlines, Feeds */
/* Note: innerHTML usage follows TT-RSS plugin conventions; all user data is escaped via App.escapeHtml() */

if (typeof Plugins === "undefined") window.Plugins = {};

Plugins.Enhanced_Search = {

	/**
	 * Zeigt das Dropdown mit gespeicherten Suchen an.
	 */
	showSaved: function() {
		const dialog = new dijit.Dialog({
			title: "Gespeicherte Suchen",
			style: "width: 500px",
			content: "<div id='es-saved-dialog-content'>Lade...</div>"
		});
		dialog.show();

		xhr.json("backend.php", {op: "pluginhandler", plugin: "enhanced_search", method: "get_searches"}, (reply) => {
			let html = "";

			if (reply.length === 0) {
				html = "<p>Keine gespeicherten Suchen vorhanden.</p>";
			} else {
				html = "<table width='100%' class='es-search-table'>";
				html += "<tr><th>Titel</th><th>Suchabfrage</th><th></th></tr>";
				for (const s of reply) {
					html += `<tr>
						<td><a href="#" onclick="Plugins.Enhanced_Search.runSearch(${parseInt(s.id, 10)}); return false;">${App.escapeHtml(s.title)}</a></td>
						<td>${App.escapeHtml(s.query)}</td>
						<td><i class="material-icons" style="cursor:pointer" onclick="Plugins.Enhanced_Search.removeSearch(${parseInt(s.id, 10)})" title="Löschen">delete</i></td>
					</tr>`;
				}
				html += "</table>";
			}

			html += `<hr/><button dojoType="dijit.form.Button" onclick="Plugins.Enhanced_Search.saveCurrentSearch()">Aktuelle Suche speichern</button>`;

			const container = document.getElementById("es-saved-dialog-content");
			if (container) {
				container.innerHTML = html; // escaped via App.escapeHtml above
				dojo.parser.parse(container);
			}
		});
	},

	/**
	 * Speichert die aktuelle Suche mit Titel-Abfrage.
	 */
	saveCurrentSearch: function() {
		const searchInput = document.querySelector("#toolbar_search_query, input[name='search']");
		const currentQuery = searchInput ? searchInput.value : "";

		if (!currentQuery) {
			Notify.error("Keine aktive Suche vorhanden.");
			return;
		}

		const title = prompt("Titel für die Suche:", currentQuery);
		if (!title) return;

		xhr.json("backend.php", {
			op: "pluginhandler",
			plugin: "enhanced_search",
			method: "save_search",
			title: title,
			query: currentQuery
		}, (reply) => {
			if (reply.status === "ok") {
				Notify.info("Suche gespeichert.");
			} else {
				Notify.error(reply.message || "Fehler beim Speichern.");
			}
		});
	},

	/**
	 * Führt eine gespeicherte Suche aus.
	 */
	runSearch: function(id) {
		xhr.json("backend.php", {
			op: "pluginhandler",
			plugin: "enhanced_search",
			method: "execute_search",
			id: id
		}, (reply) => {
			if (reply.status === "ok") {
				dijit.registry.filter((w) => w.declaredClass === "dijit.Dialog").forEach((d) => d.hide());

				const feedId = reply.feed_id || -4;
				Feeds.open({feed: feedId, is_cat: false, search: reply.query});
			} else {
				Notify.error(reply.message || "Fehler beim Ausführen der Suche.");
			}
		});
	},

	/**
	 * Entfernt eine gespeicherte Suche.
	 */
	removeSearch: function(id) {
		if (!confirm("Suche wirklich löschen?")) return;

		xhr.json("backend.php", {
			op: "pluginhandler",
			plugin: "enhanced_search",
			method: "delete_search",
			id: id
		}, (reply) => {
			if (reply.status === "ok") {
				Notify.info("Suche gelöscht.");
				Plugins.Enhanced_Search.showSaved();
			} else {
				Notify.error("Fehler beim Löschen.");
			}
		});
	},

	/**
	 * Neue Suche über Prefs-Formular hinzufügen.
	 */
	addSearch: function() {
		const title = dijit.byId("es-new-title") ? dijit.byId("es-new-title").get("value") : "";
		const query = dijit.byId("es-new-query") ? dijit.byId("es-new-query").get("value") : "";
		const feedId = dijit.byId("es-new-feed-id") ? dijit.byId("es-new-feed-id").get("value") : "";
		const catId = dijit.byId("es-new-cat-id") ? dijit.byId("es-new-cat-id").get("value") : "";

		if (!title || !query) {
			Notify.error("Titel und Suchabfrage sind erforderlich.");
			return;
		}

		xhr.json("backend.php", {
			op: "pluginhandler",
			plugin: "enhanced_search",
			method: "save_search",
			title: title,
			query: query,
			feed_id: feedId,
			cat_id: catId
		}, (reply) => {
			if (reply.status === "ok") {
				Notify.info("Suche gespeichert.");
				Plugins.Enhanced_Search.loadPrefsSearches();
				if (dijit.byId("es-new-title")) dijit.byId("es-new-title").set("value", "");
				if (dijit.byId("es-new-query")) dijit.byId("es-new-query").set("value", "");
				if (dijit.byId("es-new-feed-id")) dijit.byId("es-new-feed-id").set("value", "");
				if (dijit.byId("es-new-cat-id")) dijit.byId("es-new-cat-id").set("value", "");
			} else {
				Notify.error(reply.message || "Fehler beim Speichern.");
			}
		});
	},

	/**
	 * Lädt die Suchliste im Prefs-Bereich.
	 */
	loadPrefsSearches: function() {
		xhr.json("backend.php", {op: "pluginhandler", plugin: "enhanced_search", method: "get_searches"}, (reply) => {
			const container = document.getElementById("es-search-list");
			if (!container) return;

			if (reply.length === 0) {
				container.textContent = "Keine gespeicherten Suchen vorhanden.";
				return;
			}

			let html = "<table width='100%' class='es-search-table prefPrefsList'>";
			html += "<tr><th>Titel</th><th>Suchabfrage</th><th>Erstellt</th><th></th></tr>";
			for (const s of reply) {
				html += `<tr>
					<td>${App.escapeHtml(s.title)}</td>
					<td>${App.escapeHtml(s.query)}</td>
					<td>${App.escapeHtml(s.created_at || '')}</td>
					<td><i class="material-icons" style="cursor:pointer" onclick="Plugins.Enhanced_Search.removeSearch(${parseInt(s.id, 10)})" title="Löschen">delete</i></td>
				</tr>`;
			}
			html += "</table>";
			container.innerHTML = html; // escaped via App.escapeHtml above
		});
	}
};
