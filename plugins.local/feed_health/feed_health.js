Plugins.Feed_Health = {

	showDashboard: function() {
		xhr.json("backend.php", {
			op: "pluginhandler",
			plugin: "feed_health",
			method: "get_dashboard"
		}, (data) => {
			if (!data) {
				Notify.error("Keine Daten verfügbar.");
				return;
			}

			const dialog = new dijit.Dialog({
				title: "Feed-Gesundheit",
				style: "width: 700px; max-height: 85vh;",
				content: Plugins.Feed_Health._buildDashboardHtml(data)
			});

			dialog.show();
		});
	},

	_buildDashboardHtml: function(data) {
		const s = data.summary;
		let html = '<div class="fh-dashboard">';

		// Übersichtskarten
		html += '<div class="fh-summary">';
		html += '<div class="fh-stat fh-healthy"><div class="fh-stat-value">'
			+ s.healthy + '</div><div class="fh-stat-label">Gesund</div></div>';
		html += '<div class="fh-stat fh-stale"><div class="fh-stat-value">'
			+ s.stale + '</div><div class="fh-stat-label">Inaktiv</div></div>';
		html += '<div class="fh-stat fh-error"><div class="fh-stat-value">'
			+ s.error + '</div><div class="fh-stat-label">Fehler</div></div>';
		html += '<div class="fh-stat fh-total"><div class="fh-stat-value">'
			+ s.total + '</div><div class="fh-stat-label">Gesamt</div></div>';
		html += '</div>';

		// Fortschrittsbalken
		if (s.total > 0) {
			const healthyPct = Math.round((s.healthy / s.total) * 100);
			const stalePct = Math.round((s.stale / s.total) * 100);
			const errorPct = Math.round((s.error / s.total) * 100);

			html += '<div class="fh-bar-container">';
			html += '<div class="fh-bar-healthy" style="width:' + healthyPct + '%"></div>';
			html += '<div class="fh-bar-stale" style="width:' + stalePct + '%"></div>';
			html += '<div class="fh-bar-error" style="width:' + errorPct + '%"></div>';
			html += '</div>';
		}

		// Problem-Feeds
		if (data.problem_feeds && data.problem_feeds.length > 0) {
			html += '<h3>Problem-Feeds</h3>';
			html += '<table class="fh-table"><thead><tr>';
			html += '<th>Status</th><th>Feed</th><th>Details</th><th>Letztes Update</th>';
			html += '</tr></thead><tbody>';

			data.problem_feeds.forEach((f) => {
				const statusIcon = f.status === "error"
					? '<span class="fh-badge fh-badge-error">Fehler</span>'
					: '<span class="fh-badge fh-badge-stale">Inaktiv</span>';
				const detail = f.error
					? this._esc(f.error).substring(0, 80)
					: "Kein Update seit " + this._timeAgo(f.last_success);

				html += '<tr>';
				html += '<td>' + statusIcon + '</td>';
				html += '<td class="fh-feed-name">' + this._esc(f.title) + '</td>';
				html += '<td class="fh-detail">' + detail + '</td>';
				html += '<td>' + (f.last_success ? this._timeAgo(f.last_success) : '—') + '</td>';
				html += '</tr>';
			});

			html += '</tbody></table>';
		} else {
			html += '<p style="color: #40c463; margin-top: 16px;">'
				+ '<i class="material-icons" style="vertical-align: middle;">check_circle</i> '
				+ 'Alle Feeds sind gesund!</p>';
		}

		// Letzte Fehler
		if (data.recent_errors && data.recent_errors.length > 0) {
			html += '<h3>Letzte Fehler</h3>';
			html += '<div class="fh-error-log">';
			data.recent_errors.forEach((e) => {
				html += '<div class="fh-error-entry">';
				html += '<span class="fh-error-time">' + this._formatTime(e.time) + '</span> ';
				html += '<strong>' + this._esc(e.feed) + '</strong>: ';
				html += '<span class="fh-error-msg">' + this._esc(e.error || e.status) + '</span>';
				html += '</div>';
			});
			html += '</div>';
		}

		html += '</div>';
		return html;
	},

	showFeedHistory: function(feedId) {
		xhr.json("backend.php", {
			op: "pluginhandler",
			plugin: "feed_health",
			method: "get_feed_history",
			feed_id: feedId
		}, (history) => {
			if (!history || history.length === 0) {
				Notify.info("Kein Verlauf für diesen Feed.");
				return;
			}

			let html = '<div class="fh-history">';
			html += '<table class="fh-table"><thead><tr>';
			html += '<th>Zeitpunkt</th><th>Status</th><th>Meldung</th>';
			html += '</tr></thead><tbody>';

			history.forEach((h) => {
				const cls = h.status === "ok" ? "fh-badge-ok" : "fh-badge-error";
				html += '<tr>';
				html += '<td>' + Plugins.Feed_Health._formatTime(h.time) + '</td>';
				html += '<td><span class="fh-badge ' + cls + '">' + h.status + '</span></td>';
				html += '<td>' + Plugins.Feed_Health._esc(h.error || '—') + '</td>';
				html += '</tr>';
			});

			html += '</tbody></table></div>';

			const dialog = new dijit.Dialog({
				title: "Feed-Verlauf",
				style: "width: 600px; max-height: 70vh;",
				content: html
			});
			dialog.show();
		});
	},

	_timeAgo: function(dateStr) {
		if (!dateStr) return "—";
		const date = new Date(dateStr);
		const now = new Date();
		const diffMs = now - date;
		const diffDays = Math.floor(diffMs / 86400000);
		const diffHours = Math.floor(diffMs / 3600000);

		if (diffDays > 0) return diffDays + "d";
		if (diffHours > 0) return diffHours + "h";
		return "gerade";
	},

	_formatTime: function(dateStr) {
		if (!dateStr) return "—";
		const d = new Date(dateStr);
		return d.toLocaleDateString("de-DE", {day: "2-digit", month: "2-digit"})
			+ " " + d.toLocaleTimeString("de-DE", {hour: "2-digit", minute: "2-digit"});
	},

	_esc: function(str) {
		if (!str) return "";
		const d = document.createElement("div");
		d.textContent = str;
		return d.innerHTML;
	}
};
