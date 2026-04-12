Plugins.Digest_View = {

	showLatest: function() {
		xhr.json("backend.php", {
			op: "pluginhandler",
			plugin: "digest_view",
			method: "view_latest"
		}, (data) => {
			if (data.error) {
				Notify.info(data.error);
				return;
			}

			const dialog = new dijit.Dialog({
				title: data.title || "Digest",
				style: "width: 750px; max-height: 85vh;",
				content: '<div class="dv-viewer">'
					+ '<div class="dv-header">'
					+ '<span class="dv-count">' + data.count + ' Artikel</span>'
					+ '<span class="dv-date">' + Plugins.Digest_View._formatDate(data.date) + '</span>'
					+ '<button class="dv-archive-btn" onclick="Plugins.Digest_View.showArchive()">'
					+ 'Archiv</button>'
					+ '</div>'
					+ data.html
					+ '</div>'
			});

			dialog.show();
		});
	},

	showArchive: function() {
		xhr.json("backend.php", {
			op: "pluginhandler",
			plugin: "digest_view",
			method: "view_archive",
			offset: 0
		}, (issues) => {
			if (!issues || issues.length === 0) {
				Notify.info("Kein Digest-Archiv vorhanden.");
				return;
			}

			let html = '<div class="dv-archive">';
			html += '<table class="dv-table"><thead><tr>';
			html += '<th>Datum</th><th>Titel</th><th>Artikel</th><th></th>';
			html += '</tr></thead><tbody>';

			issues.forEach((issue) => {
				html += '<tr>';
				html += '<td>' + Plugins.Digest_View._formatDate(issue.date) + '</td>';
				html += '<td>' + Plugins.Digest_View._esc(issue.title) + '</td>';
				html += '<td>' + issue.count + '</td>';
				html += '<td><button onclick="Plugins.Digest_View.showIssue(' + issue.id + ')">'
					+ 'Anzeigen</button></td>';
				html += '</tr>';
			});

			html += '</tbody></table></div>';

			const dialog = new dijit.Dialog({
				title: "Digest-Archiv",
				style: "width: 600px; max-height: 70vh;",
				content: html
			});
			dialog.show();
		});
	},

	showIssue: function(issueId) {
		xhr.json("backend.php", {
			op: "pluginhandler",
			plugin: "digest_view",
			method: "view_issue",
			issue_id: issueId
		}, (data) => {
			if (data.error) {
				Notify.error(data.error);
				return;
			}

			const dialog = new dijit.Dialog({
				title: data.title || "Digest",
				style: "width: 750px; max-height: 85vh;",
				content: '<div class="dv-viewer">'
					+ '<div class="dv-header">'
					+ '<span class="dv-count">' + data.count + ' Artikel</span>'
					+ '<span class="dv-date">' + Plugins.Digest_View._formatDate(data.date) + '</span>'
					+ '</div>'
					+ data.html
					+ '</div>'
			});
			dialog.show();
		});
	},

	generateNow: function(configId) {
		Notify.progress("Digest wird generiert...");
		xhr.json("backend.php", {
			op: "pluginhandler",
			plugin: "digest_view",
			method: "generate_now",
			config_id: configId
		}, (reply) => {
			if (reply.error) {
				Notify.error(reply.error);
			} else {
				Notify.info(reply.message || "Digest generiert.");
			}
		});
	},

	_formatDate: function(dateStr) {
		if (!dateStr) return "—";
		const d = new Date(dateStr);
		return d.toLocaleDateString("de-DE", {
			day: "2-digit", month: "2-digit", year: "numeric",
			hour: "2-digit", minute: "2-digit"
		});
	},

	_esc: function(str) {
		if (!str) return "";
		const d = document.createElement("div");
		d.textContent = str;
		return d.innerHTML;
	}
};
