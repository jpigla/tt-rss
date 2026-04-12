Plugins.Reading_Stats = {
	_readArticles: new Set(),
	_timers: {},

	init: function() {
		// Intersection Observer: Artikel als gelesen tracken wenn sichtbar
		if (typeof IntersectionObserver !== "undefined") {
			const observer = new IntersectionObserver((entries) => {
				entries.forEach((entry) => {
					if (entry.isIntersecting) {
						const el = entry.target.querySelector(".rs-tracking-data");
						if (el) {
							const articleId = el.dataset.articleId;
							if (articleId && !Plugins.Reading_Stats._readArticles.has(articleId)) {
								Plugins.Reading_Stats._readArticles.add(articleId);
								Plugins.Reading_Stats._recordRead(
									articleId,
									el.dataset.feedId || 0,
									el.dataset.wordCount || 0
								);
								Plugins.Reading_Stats._startTimer(articleId);
							}
						}
					}
				});
			}, {threshold: 0.5});

			// Bestehende und neue Artikel beobachten
			const observeArticles = () => {
				document.querySelectorAll(".cdm, .post").forEach((el) => {
					if (!el.dataset.rsObserved) {
						el.dataset.rsObserved = "1";
						observer.observe(el);
					}
				});
			};

			observeArticles();
			setInterval(observeArticles, 2000);
		}
	},

	_recordRead: function(refId, feedId, wordCount) {
		xhr.json("backend.php", {
			op: "pluginhandler",
			plugin: "reading_stats",
			method: "record_read",
			ref_id: refId,
			feed_id: feedId,
			word_count: wordCount
		}, () => {});
	},

	_startTimer: function(articleId) {
		if (this._timers[articleId]) return;

		let seconds = 0;
		this._timers[articleId] = setInterval(() => {
			seconds += 10;
			// Alle 30 Sekunden an Server senden
			if (seconds % 30 === 0) {
				xhr.json("backend.php", {
					op: "pluginhandler",
					plugin: "reading_stats",
					method: "record_reading_time",
					ref_id: articleId,
					seconds: 30
				}, () => {});
			}
		}, 10000);

		// Timer nach 10 Minuten stoppen (Max-Lesezeit pro Artikel)
		setTimeout(() => {
			if (Plugins.Reading_Stats._timers[articleId]) {
				clearInterval(Plugins.Reading_Stats._timers[articleId]);
				delete Plugins.Reading_Stats._timers[articleId];
			}
		}, 600000);
	},

	showDashboard: function() {
		xhr.json("backend.php", {
			op: "pluginhandler",
			plugin: "reading_stats",
			method: "get_dashboard"
		}, (data) => {
			if (!data) {
				Notify.error("Keine Statistik-Daten verfügbar.");
				return;
			}

			const dialog = new dijit.Dialog({
				title: "Lese-Statistiken",
				style: "width: 700px; max-height: 85vh;",
				content: Plugins.Reading_Stats._buildDashboardHtml(data)
			});

			dialog.show();
		});
	},

	_buildDashboardHtml: function(data) {
		let html = '<div class="rs-dashboard">';

		// Übersichtskarten
		html += '<div class="rs-cards">';
		html += this._card("Heute", data.today, "article");
		html += this._card("7 Tage", data.week, "date_range");
		html += this._card("30 Tage", data.month, "calendar_month");
		html += this._card("Gesamt", data.total, "library_books");
		html += '</div>';

		// Streak & Durchschnitt
		html += '<div class="rs-cards">';
		html += this._card("Streak", data.streak.current + " Tage", "local_fire_department");
		html += this._card("Rekord", data.streak.longest + " Tage", "emoji_events");
		html += this._card("⌀/Tag (7d)", data.avg_daily_7d, "speed");
		html += this._card("Lesezeit", data.total_reading_time_min + " min", "timer");
		html += '</div>';

		// Balkendiagramm (letzte 30 Tage)
		if (data.daily_counts && data.daily_counts.length > 0) {
			html += '<div class="rs-section">';
			html += '<h3>Letzte 30 Tage</h3>';
			html += this._buildBarChart(data.daily_counts);
			html += '</div>';
		}

		// Heatmap
		if (data.heatmap && data.heatmap.length > 0) {
			html += '<div class="rs-section">';
			html += '<h3>Jahresübersicht</h3>';
			html += this._buildHeatmap(data.heatmap);
			html += '</div>';
		}

		// Stunden-Verteilung
		if (data.hourly_distribution) {
			html += '<div class="rs-section">';
			html += '<h3>Tageszeit-Verteilung</h3>';
			html += this._buildHourlyChart(data.hourly_distribution);
			html += '</div>';
		}

		// Top Feeds
		if (data.top_feeds && data.top_feeds.length > 0) {
			html += '<div class="rs-section">';
			html += '<h3>Top Feeds (30 Tage)</h3>';
			html += '<table class="rs-table">';
			data.top_feeds.forEach((f, i) => {
				html += '<tr><td class="rs-rank">' + (i + 1) + '.</td>';
				html += '<td class="rs-feed-title">' + this._esc(f.title) + '</td>';
				html += '<td class="rs-count">' + f.count + '</td></tr>';
			});
			html += '</table>';
			html += '</div>';
		}

		html += '</div>';
		return html;
	},

	_card: function(label, value, icon) {
		return '<div class="rs-card">'
			+ '<i class="material-icons">' + icon + '</i>'
			+ '<div class="rs-card-value">' + value + '</div>'
			+ '<div class="rs-card-label">' + label + '</div>'
			+ '</div>';
	},

	_buildBarChart: function(dailyCounts) {
		const maxCount = Math.max(...dailyCounts.map(d => d.count), 1);
		let html = '<div class="rs-bar-chart">';
		dailyCounts.forEach((d) => {
			const pct = Math.round((d.count / maxCount) * 100);
			const dayLabel = d.date.substring(5); // MM-DD
			html += '<div class="rs-bar-col" title="' + d.date + ': ' + d.count + ' Artikel">'
				+ '<div class="rs-bar" style="height: ' + pct + '%;"></div>'
				+ '<div class="rs-bar-label">' + (dailyCounts.length <= 15 ? dayLabel : '') + '</div>'
				+ '</div>';
		});
		html += '</div>';
		return html;
	},

	_buildHeatmap: function(heatmapData) {
		// Daten in Map umwandeln
		const dataMap = {};
		heatmapData.forEach(d => { dataMap[d.date] = d.count; });
		const maxCount = Math.max(...heatmapData.map(d => d.count), 1);

		// 52 Wochen zurückgehen
		const today = new Date();
		const start = new Date(today);
		start.setDate(start.getDate() - 364);
		// Auf Montag ausrichten
		while (start.getDay() !== 1) start.setDate(start.getDate() - 1);

		let html = '<div class="rs-heatmap">';
		const current = new Date(start);

		while (current <= today) {
			if (current.getDay() === 1) {
				html += '<div class="rs-heatmap-week">';
			}

			const dateStr = current.toISOString().substring(0, 10);
			const count = dataMap[dateStr] || 0;
			const level = count === 0 ? 0 : Math.min(4, Math.ceil((count / maxCount) * 4));

			html += '<div class="rs-heatmap-day rs-level-' + level + '" '
				+ 'title="' + dateStr + ': ' + count + ' Artikel"></div>';

			if (current.getDay() === 0) {
				html += '</div>';
			}

			current.setDate(current.getDate() + 1);
		}

		// Letzte unvollständige Woche schließen
		if (current.getDay() !== 1) html += '</div>';

		html += '</div>';
		return html;
	},

	_buildHourlyChart: function(hours) {
		const maxH = Math.max(...hours, 1);
		let html = '<div class="rs-hourly-chart">';
		hours.forEach((cnt, h) => {
			const pct = Math.round((cnt / maxH) * 100);
			html += '<div class="rs-hour-col" title="' + h + ':00 — ' + cnt + ' Artikel">'
				+ '<div class="rs-hour-bar" style="height: ' + pct + '%;"></div>'
				+ '<div class="rs-hour-label">' + (h % 3 === 0 ? h : '') + '</div>'
				+ '</div>';
		});
		html += '</div>';
		return html;
	},

	_esc: function(str) {
		const d = document.createElement("div");
		d.textContent = str;
		return d.innerHTML;
	}
};

// Auto-Init
document.addEventListener("DOMContentLoaded", () => {
	if (typeof Plugins !== "undefined" && Plugins.Reading_Stats) {
		Plugins.Reading_Stats.init();
	}
});
