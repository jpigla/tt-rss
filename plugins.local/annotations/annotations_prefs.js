/* global xhr, Notify, fox, App */

var Annotations_Prefs = {

	_currentPage: 1,
	_domainsLoaded: false,
	_initialized: false,

	init: function () {
		if (Annotations_Prefs._initialized) return;
		Annotations_Prefs._initialized = true;
		Annotations_Prefs.loadPage(1);
	},

	// ── Daten laden und rendern ─────────────────────────

	loadPage: function (page) {
		var params = {
			op: 'pluginhandler',
			plugin: 'annotations',
			method: 'get_prefs_data',
			page: page || 1
		};

		var dateFrom = document.getElementById('ann-filter-date-from');
		var dateTo = document.getElementById('ann-filter-date-to');
		var domain = document.getElementById('ann-filter-domain');

		if (dateFrom && dateFrom.value) params.date_from = dateFrom.value;
		if (dateTo && dateTo.value) params.date_to = dateTo.value;
		if (domain && domain.value) params.domain = domain.value;

		xhr.json("backend.php", params, function (reply) {
			if (!reply) return;

			Annotations_Prefs._currentPage = reply.page;
			Annotations_Prefs._renderStats(reply);
			Annotations_Prefs._renderDomains(reply.domains);
			Annotations_Prefs._renderGroups(reply.groups);
			Annotations_Prefs._renderPagination(reply.page, reply.total_pages);
		});
	},

	_renderStats: function (data) {
		var el = document.getElementById('ann-prefs-stats');
		if (!el) return;

		var filtered = (data.filtered_annotations !== data.total_annotations);
		var text = data.total_annotations + ' Annotationen in ' +
			data.total_articles + ' Artikeln';

		if (filtered) {
			text += ' — Filter aktiv: ' + data.filtered_annotations +
				' Annotationen in ' + data.filtered_articles + ' Artikeln';
		}

		el.textContent = text;
	},

	_renderDomains: function (domains) {
		if (Annotations_Prefs._domainsLoaded) return;

		var sel = document.getElementById('ann-filter-domain');
		if (!sel || !domains) return;

		domains.forEach(function (d) {
			var opt = document.createElement('option');
			opt.value = d;
			opt.textContent = d;
			sel.appendChild(opt);
		});

		Annotations_Prefs._domainsLoaded = true;
	},

	_renderGroups: function (groups) {
		var el = document.getElementById('ann-prefs-content');
		if (!el) return;

		if (!groups || groups.length === 0) {
			el.textContent = '';
			var p = document.createElement('p');
			p.textContent = 'Keine Annotationen gefunden.';
			el.appendChild(p);
			return;
		}

		// Container leeren
		el.textContent = '';

		groups.forEach(function (group) {
			var div = document.createElement('div');
			div.className = 'ann-group';

			// Header
			var header = document.createElement('div');
			header.className = 'ann-group-header';

			var titleLink = document.createElement('a');
			titleLink.href = '#';
			titleLink.className = 'ann-group-title';
			titleLink.textContent = group.title;
			titleLink.setAttribute('data-ref-id', group.ref_id);
			titleLink.addEventListener('click', function (e) {
				e.preventDefault();
				Annotations_Prefs.openArticle(parseInt(this.getAttribute('data-ref-id')));
			});
			header.appendChild(titleLink);

			var count = document.createElement('span');
			count.className = 'ann-group-count';
			count.textContent = '\u00a0— ' + group.annotations.length + ' Annotation(en)';
			header.appendChild(count);

			var csvBtn = document.createElement('button');
			csvBtn.className = 'btn btn-xs';
			csvBtn.setAttribute('data-ref-id', group.ref_id);
			csvBtn.addEventListener('click', function () {
				Annotations_Prefs.exportCsv(parseInt(this.getAttribute('data-ref-id')));
			});
			var csvIcon = document.createElement('i');
			csvIcon.className = 'material-icons';
			csvIcon.textContent = 'download';
			csvBtn.appendChild(csvIcon);
			csvBtn.appendChild(document.createTextNode(' CSV'));
			header.appendChild(csvBtn);

			div.appendChild(header);

			// Tabelle
			var table = document.createElement('table');
			table.className = 'ann-group-table';
			table.width = '100%';

			var titleRow = document.createElement('tr');
			titleRow.className = 'title';
			['Markierter Text', 'Notiz', 'Datum', ''].forEach(function (label) {
				var td = document.createElement('td');
				td.textContent = label;
				titleRow.appendChild(td);
			});
			table.appendChild(titleRow);

			group.annotations.forEach(function (ann) {
				var tr = document.createElement('tr');

				// Markierter Text
				var td1 = document.createElement('td');
				var dot = document.createElement('span');
				dot.className = 'ann-color-dot';
				dot.style.background = ann.color || '#fff3cd';
				td1.appendChild(dot);
				var textContent = ann.highlighted_text || '';
				td1.appendChild(document.createTextNode(
					textContent.length > 100 ? textContent.substring(0, 100) + '\u2026' : textContent
				));
				tr.appendChild(td1);

				// Notiz
				var td2 = document.createElement('td');
				td2.className = 'ann-note-cell';
				var noteContent = ann.note || '';
				td2.textContent = noteContent.length > 150 ? noteContent.substring(0, 150) + '\u2026' : noteContent;
				tr.appendChild(td2);

				// Datum
				var td3 = document.createElement('td');
				td3.className = 'ann-date-cell';
				td3.textContent = ann.formatted_date || '';
				tr.appendChild(td3);

				// Aktionen
				var td4 = document.createElement('td');
				td4.className = 'ann-actions-cell';

				var copyIcon = document.createElement('i');
				copyIcon.className = 'material-icons ann-action-icon';
				copyIcon.textContent = 'content_copy';
				copyIcon.title = 'Kopieren';
				copyIcon.setAttribute('data-text', ann.highlighted_text || '');
				copyIcon.setAttribute('data-note', ann.note || '');
				copyIcon.addEventListener('click', function () {
					Annotations_Prefs.copy(ann.id, this);
				});
				td4.appendChild(copyIcon);

				var delIcon = document.createElement('i');
				delIcon.className = 'material-icons ann-action-icon ann-delete-icon';
				delIcon.textContent = 'delete';
				delIcon.title = 'Löschen';
				delIcon.setAttribute('data-ann-id', ann.id);
				delIcon.addEventListener('click', function () {
					Annotations_Prefs.deleteAnn(parseInt(this.getAttribute('data-ann-id')));
				});
				td4.appendChild(delIcon);

				tr.appendChild(td4);
				table.appendChild(tr);
			});

			div.appendChild(table);
			el.appendChild(div);
		});
	},

	_renderPagination: function (currentPage, totalPages) {
		var el = document.getElementById('ann-prefs-pagination');
		if (!el) return;

		el.textContent = '';

		if (totalPages <= 1) return;

		var info = document.createElement('span');
		info.className = 'ann-page-info';
		info.textContent = 'Seite ' + currentPage + ' von ' + totalPages;

		var prevBtn = document.createElement('button');
		prevBtn.className = 'btn btn-xs';
		prevBtn.textContent = '\u25c0 Zurück';
		prevBtn.disabled = (currentPage <= 1);
		prevBtn.addEventListener('click', function () {
			Annotations_Prefs.loadPage(currentPage - 1);
		});

		var nextBtn = document.createElement('button');
		nextBtn.className = 'btn btn-xs';
		nextBtn.textContent = 'Weiter \u25b6';
		nextBtn.disabled = (currentPage >= totalPages);
		nextBtn.addEventListener('click', function () {
			Annotations_Prefs.loadPage(currentPage + 1);
		});

		el.appendChild(prevBtn);
		el.appendChild(info);
		el.appendChild(nextBtn);
	},

	// ── Filter ──────────────────────────────────────────

	resetFilters: function () {
		var dateFrom = document.getElementById('ann-filter-date-from');
		var dateTo = document.getElementById('ann-filter-date-to');
		var domain = document.getElementById('ann-filter-domain');

		if (dateFrom) dateFrom.value = '';
		if (dateTo) dateTo.value = '';
		if (domain) domain.value = '';

		Annotations_Prefs.loadPage(1);
	},

	// ── Artikel-Popup ───────────────────────────────────

	openArticle: function (refId) {
		xhr.json("backend.php", {op: "pluginhandler", plugin: "annotations", method: "view_article", ref_id: refId}, function (reply) {
			if (!reply || reply.error) {
				Notify.error(reply ? reply.error : 'Fehler');
				return;
			}

			var wrapper = document.createElement('div');
			wrapper.style.cssText = 'max-height:70vh;overflow:auto;padding:10px;line-height:1.6';

			if (reply.link) {
				var linkP = document.createElement('p');
				var a = document.createElement('a');
				a.href = reply.link;
				a.target = '_blank';
				a.rel = 'noopener';
				var linkIcon = document.createElement('i');
				linkIcon.className = 'material-icons';
				linkIcon.style.cssText = 'font-size:14px;vertical-align:middle';
				linkIcon.textContent = 'open_in_new';
				a.appendChild(linkIcon);
				a.appendChild(document.createTextNode(' Original öffnen'));
				linkP.appendChild(a);
				wrapper.appendChild(linkP);
			}

			var contentDiv = document.createElement('div');
			contentDiv.className = 'ann-article-content';
			// Content serverseitig durch Sanitizer::sanitize() bereinigt (identischer Codepath wie Feed-Content)
			contentDiv.innerHTML = reply.content; // eslint-disable-line no-unsanitized/property
			wrapper.appendChild(contentDiv);

			var dialog = new fox.SingleUseDialog({
				title: reply.title,
				style: 'width:90vw;max-width:1100px',
				content: wrapper.outerHTML +
					'<footer class="text-center" style="margin-top:8px">' +
					'<button class="alt-primary" dojoType="dijit.form.Button" ' +
					'onclick="App.dialogOf(this).hide()">Schließen</button></footer>'
			});
			dialog.show();

			Annotations_Prefs._applyAnnotationsInDialog(dialog, refId);
		});
	},

	_applyAnnotationsInDialog: function (dialog, refId) {
		xhr.json("backend.php", {op: "pluginhandler", plugin: "annotations", method: "get_annotations", article_id: refId}, function (annotations) {
			if (!annotations || annotations.length === 0) return;

			var container = dialog.domNode.querySelector('.ann-article-content');
			if (!container) return;

			annotations.forEach(function (ann) {
				var text = ann.highlighted_text;
				if (!text) return;

				if (container.querySelector('mark[data-ann-id="' + ann.id + '"]')) return;

				var context = null;
				if (ann.selector_path) {
					try { context = JSON.parse(ann.selector_path); } catch (e) { /* ignorieren */ }
				}

				var treeWalker = document.createTreeWalker(
					container, NodeFilter.SHOW_TEXT, null, false
				);

				var node;
				var found = false;
				while ((node = treeWalker.nextNode())) {
					if (node.parentElement && node.parentElement.classList &&
						node.parentElement.classList.contains('ann-highlight')) continue;

					var searchFrom = 0;
					while (true) {
						var idx = node.textContent.indexOf(text, searchFrom);
						if (idx === -1) break;

						if (context && context.before && context.before.length >= 5) {
							var beforeInDoc = node.textContent.substring(Math.max(0, idx - 20), idx);
							var checkStr = context.before.slice(-10);
							if (beforeInDoc.indexOf(checkStr) === -1) {
								searchFrom = idx + 1;
								continue;
							}
						}

						try {
							var mark = document.createElement('mark');
							mark.className = 'ann-highlight';
							mark.style.backgroundColor = ann.color || '#fff3cd';
							mark.dataset.annId = ann.id;
							if (ann.note) {
								mark.title = ann.note;
								mark.classList.add('ann-has-note');
							}

							var range = document.createRange();
							range.setStart(node, idx);
							range.setEnd(node, Math.min(idx + text.length, node.textContent.length));
							range.surroundContents(mark);
							found = true;
						} catch (e) { /* DOM-Grenzüberschreitung */ }
						break;
					}
					if (found) break;
				}
			});
		});
	},

	// ── Export ───────────────────────────────────────────

	exportCsv: function (refId) {
		window.open('backend.php?op=pluginhandler&plugin=annotations&method=export_csv&ref_id=' + refId, '_blank');
	},

	exportAllCsv: function () {
		window.open('backend.php?op=pluginhandler&plugin=annotations&method=export_all_csv', '_blank');
	},

	// ── Aktionen ────────────────────────────────────────

	copy: function (annId, el) {
		var text = el.getAttribute('data-text') || '';
		var note = el.getAttribute('data-note') || '';
		var copyText = text + (note ? '\n\nNotiz: ' + note : '');

		if (navigator.clipboard) {
			navigator.clipboard.writeText(copyText).then(function () {
				Notify.info('In Zwischenablage kopiert');
			});
		}
	},

	deleteAnn: function (annId) {
		if (!confirm('Annotation wirklich löschen?')) return;

		xhr.json("backend.php", {op: "pluginhandler", plugin: "annotations", method: "delete_annotation", id: annId}, function (reply) {
			if (reply && reply.status === 'ok') {
				Notify.info('Annotation gelöscht');
				Annotations_Prefs.loadPage(Annotations_Prefs._currentPage);
			}
		});
	}
};

