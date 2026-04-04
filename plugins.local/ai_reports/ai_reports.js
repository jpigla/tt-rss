/* global Plugins, Notify, xhr, Headlines */

Plugins.Ai_Reports = {
	openDialog: function() {
		var existing = document.getElementById('ai-reports-overlay');
		if (existing) existing.remove();
		existing = document.getElementById('ai-reports-dialog');
		if (existing) existing.remove();

		var overlay = document.createElement('div');
		overlay.id = 'ai-reports-overlay';
		overlay.className = 'ai-reports-overlay';
		overlay.onclick = function() {
			overlay.remove();
			document.getElementById('ai-reports-dialog').remove();
		};

		var dlg = document.createElement('div');
		dlg.id = 'ai-reports-dialog';
		dlg.className = 'ai-reports-dialog';

		// Header
		var header = document.createElement('div');
		header.className = 'ai-reports-dialog-header';

		var icon = document.createElement('i');
		icon.className = 'material-icons';
		icon.textContent = 'assessment';
		header.appendChild(icon);

		var title = document.createElement('span');
		title.textContent = 'Report erstellen';
		header.appendChild(title);

		var closeBtn = document.createElement('i');
		closeBtn.className = 'material-icons';
		closeBtn.textContent = 'close';
		closeBtn.style.cssText = 'cursor:pointer;margin-left:auto';
		closeBtn.onclick = function() {
			overlay.remove();
			dlg.remove();
		};
		header.appendChild(closeBtn);

		dlg.appendChild(header);

		// Body
		var body = document.createElement('div');
		body.className = 'ai-reports-dialog-body';

		// Tabs: Ausgewählte Artikel / Feed + Zeitraum
		var tabBar = document.createElement('div');
		tabBar.className = 'ai-reports-tabs';

		var tabSelection = document.createElement('button');
		tabSelection.className = 'ai-reports-tab active';
		tabSelection.textContent = 'Markierte Artikel';
		tabSelection.onclick = function() {
			tabSelection.classList.add('active');
			tabFeed.classList.remove('active');
			selectionPanel.style.display = '';
			feedPanel.style.display = 'none';
		};
		tabBar.appendChild(tabSelection);

		var tabFeed = document.createElement('button');
		tabFeed.className = 'ai-reports-tab';
		tabFeed.textContent = 'Feed + Zeitraum';
		tabFeed.onclick = function() {
			tabFeed.classList.add('active');
			tabSelection.classList.remove('active');
			feedPanel.style.display = '';
			selectionPanel.style.display = 'none';
			Plugins.Ai_Reports._loadFeeds(feedSelect);
		};
		tabBar.appendChild(tabFeed);

		body.appendChild(tabBar);

		// Selection panel
		var selectionPanel = document.createElement('div');
		selectionPanel.id = 'ai-reports-selection-panel';

		var selInfo = document.createElement('p');
		selInfo.style.cssText = 'font-size:13px;color:var(--fg-secondary,#888)';
		selInfo.textContent = 'Markiere Artikel in der Übersicht (Checkbox) und klicke dann auf "Report generieren".';
		selectionPanel.appendChild(selInfo);

		body.appendChild(selectionPanel);

		// Feed panel
		var feedPanel = document.createElement('div');
		feedPanel.id = 'ai-reports-feed-panel';
		feedPanel.style.display = 'none';

		var feedLabel = document.createElement('label');
		feedLabel.textContent = 'Feed:';
		feedLabel.style.cssText = 'display:block;margin-bottom:4px;font-weight:600;font-size:13px';
		feedPanel.appendChild(feedLabel);

		var feedSelect = document.createElement('select');
		feedSelect.id = 'ai-reports-feed-select';
		feedSelect.style.cssText = 'width:100%;padding:6px;margin-bottom:12px;border:1px solid var(--border-color,#ddd);border-radius:4px';

		var defaultOpt = document.createElement('option');
		defaultOpt.value = '';
		defaultOpt.textContent = 'Feed wird geladen...';
		feedSelect.appendChild(defaultOpt);

		feedPanel.appendChild(feedSelect);

		var daysLabel = document.createElement('label');
		daysLabel.textContent = 'Zeitraum (Tage):';
		daysLabel.style.cssText = 'display:block;margin-bottom:4px;font-weight:600;font-size:13px';
		feedPanel.appendChild(daysLabel);

		var daysInput = document.createElement('input');
		daysInput.type = 'number';
		daysInput.id = 'ai-reports-days';
		daysInput.value = '7';
		daysInput.min = '1';
		daysInput.max = '90';
		daysInput.style.cssText = 'width:80px;padding:6px;border:1px solid var(--border-color,#ddd);border-radius:4px';
		feedPanel.appendChild(daysInput);

		body.appendChild(feedPanel);

		dlg.appendChild(body);

		// Footer
		var footer = document.createElement('div');
		footer.className = 'ai-reports-dialog-footer';

		var generateBtn = document.createElement('button');
		generateBtn.className = 'ai-reports-generate-btn';
		generateBtn.textContent = 'Report generieren';
		generateBtn.onclick = function() {
			Plugins.Ai_Reports._generate(overlay, dlg);
		};
		footer.appendChild(generateBtn);

		dlg.appendChild(footer);

		document.body.appendChild(overlay);
		document.body.appendChild(dlg);
	},

	_loadFeeds: function(select) {
		if (select.dataset.loaded) return;

		xhr.json("backend.php", {
			op: "pluginhandler", plugin: "ai_reports", method: "get_feeds"
		}, function(feeds) {
			// Optionen leeren
			while (select.firstChild) select.removeChild(select.firstChild);

			var defaultOpt = document.createElement('option');
			defaultOpt.value = '';
			defaultOpt.textContent = '-- Feed auswählen --';
			select.appendChild(defaultOpt);

			feeds.forEach(function(f) {
				var opt = document.createElement('option');
				opt.value = f.id;
				opt.textContent = f.title;
				select.appendChild(opt);
			});

			select.dataset.loaded = '1';
		});
	},

	_generate: function(overlay, dlg) {
		var params = {
			op: "pluginhandler", plugin: "ai_reports", method: "generate"
		};

		var selectionPanel = document.getElementById('ai-reports-selection-panel');
		if (selectionPanel && selectionPanel.style.display !== 'none') {
			// Ausgewählte Artikel aus Headlines
			var ids = Plugins.Ai_Reports._getSelectedArticleIds();
			if (ids.length === 0) {
				Notify.error('Keine Artikel markiert. Bitte zuerst Artikel in der Übersicht auswählen.');
				return;
			}
			params.article_ids = ids.join(',');
		} else {
			// Feed + Zeitraum
			var feedSelect = document.getElementById('ai-reports-feed-select');
			var daysInput = document.getElementById('ai-reports-days');

			if (!feedSelect || !feedSelect.value) {
				Notify.error('Bitte einen Feed auswählen.');
				return;
			}

			params.feed_id = feedSelect.value;
			params.days = daysInput ? daysInput.value : '7';
		}

		overlay.remove();
		dlg.remove();

		Notify.progress('Report wird generiert... Dies kann einen Moment dauern.');

		xhr.json("backend.php", params, function(reply) {
			if (reply.error) {
				Notify.error(reply.error);
				return;
			}

			Notify.close();
			Plugins.Ai_Reports._showReport(reply);
		});
	},

	_getSelectedArticleIds: function() {
		var ids = [];
		var rows = document.querySelectorAll('.hl.Selected, .cdm.Selected');
		rows.forEach(function(row) {
			var id = row.id.replace('RROW-', '');
			if (id) ids.push(id);
		});

		// Fallback: sichtbare Artikel nehmen (letzte 10)
		if (ids.length === 0) {
			var allRows = document.querySelectorAll('[id^="RROW-"]');
			var count = 0;
			allRows.forEach(function(row) {
				if (count >= 10) return;
				var id = row.id.replace('RROW-', '');
				if (id) {
					ids.push(id);
					count++;
				}
			});
		}

		return ids;
	},

	_showReport: function(data) {
		var existing = document.getElementById('ai-reports-view-overlay');
		if (existing) existing.remove();
		existing = document.getElementById('ai-reports-view-dialog');
		if (existing) existing.remove();

		var overlay = document.createElement('div');
		overlay.id = 'ai-reports-view-overlay';
		overlay.className = 'ai-reports-overlay';
		overlay.onclick = function() {
			overlay.remove();
			document.getElementById('ai-reports-view-dialog').remove();
		};

		var dlg = document.createElement('div');
		dlg.id = 'ai-reports-view-dialog';
		dlg.className = 'ai-reports-dialog ai-reports-view';

		// Header
		var header = document.createElement('div');
		header.className = 'ai-reports-dialog-header';

		var icon = document.createElement('i');
		icon.className = 'material-icons';
		icon.textContent = 'assessment';
		header.appendChild(icon);

		var title = document.createElement('span');
		title.textContent = data.title;
		header.appendChild(title);

		var meta = document.createElement('span');
		meta.style.cssText = 'margin-left:auto;font-size:11px;color:var(--fg-secondary,#888);font-weight:400';
		meta.textContent = (data.model || '') + ' | ' + (data.article_count || '?') + ' Artikel';
		header.appendChild(meta);

		var closeBtn = document.createElement('i');
		closeBtn.className = 'material-icons';
		closeBtn.textContent = 'close';
		closeBtn.style.cssText = 'cursor:pointer;margin-left:8px';
		closeBtn.onclick = function() {
			overlay.remove();
			dlg.remove();
		};
		header.appendChild(closeBtn);

		dlg.appendChild(header);

		// Body
		var body = document.createElement('div');
		body.className = 'ai-reports-dialog-body ai-reports-report-text';
		body.textContent = data.report_text;
		dlg.appendChild(body);

		document.body.appendChild(overlay);
		document.body.appendChild(dlg);
	},

	viewReport: function(id) {
		Notify.progress('Lade Bericht...');

		xhr.json("backend.php", {
			op: "pluginhandler", plugin: "ai_reports", method: "get_report", id: id
		}, function(reply) {
			Notify.close();

			if (reply.error) {
				Notify.error(reply.error);
				return;
			}

			Plugins.Ai_Reports._showReport({
				title: reply.title,
				report_text: reply.report_text,
				model: reply.model,
				article_count: reply.source_articles ? reply.source_articles.length : 0
			});
		});
	},

	deleteReport: function(id) {
		if (!confirm('Bericht wirklich löschen?')) return;

		Notify.progress('Lösche Bericht...');

		xhr.post("backend.php", {
			op: "pluginhandler", plugin: "ai_reports", method: "delete_report", id: id
		}, function() {
			Notify.info('Bericht gelöscht');
			window.location.reload();
		});
	}
};
