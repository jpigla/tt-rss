/* ══════════════════════════════════════════════════
   Reader Mode — Standalone Page JavaScript
   Kein Dojo, kein PluginHost — reines Vanilla JS.
   AJAX via fetch() an backend.php mit Session-Cookie.
   ══════════════════════════════════════════════════ */

(function () {
	'use strict';

	var meta = window.__readerMeta || {};
	var annotations = window.__readerAnnotations || [];
	var settings = window.__readerSettings || {};
	var csrfToken = window.__csrfToken || '';

	// ── DOM-Referenzen ──────────────────────────────
	var content = document.getElementById('reader-content');
	var tocPanel = document.getElementById('reader-toc');
	var tocList = document.getElementById('toc-list');
	var sidebar = document.getElementById('reader-sidebar');
	var progressBar = document.getElementById('reader-progress-bar');
	var wrapper = content ? content.querySelector('.annotations-wrapper') : null;

	// ── Hilfsfunktionen ─────────────────────────────
	function ajax(method, params) {
		var body = new URLSearchParams();
		body.append('op', 'pluginhandler');
		body.append('plugin', params.plugin || 'reader_mode');
		body.append('method', method);
		body.append('csrf_token', csrfToken);
		Object.keys(params).forEach(function (k) {
			if (k !== 'plugin') body.append(k, params[k]);
		});
		return fetch('../../backend.php', {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		}).then(function (r) { return r.json(); });
	}

	function formatDate(str) {
		if (!str) return '\u2014';
		try {
			var d = new Date(str);
			return d.toLocaleDateString('de-DE', {
				day: '2-digit', month: '2-digit', year: 'numeric'
			}) + ', ' + d.toLocaleTimeString('de-DE', {
				hour: '2-digit', minute: '2-digit'
			});
		} catch (e) { return str; }
	}

	function relativeTime(str) {
		if (!str) return '';
		try {
			var d = new Date(str);
			var diff = (Date.now() - d.getTime()) / 1000;
			if (diff < 60) return 'gerade eben';
			if (diff < 3600) return Math.floor(diff / 60) + ' Min';
			if (diff < 86400) return Math.floor(diff / 3600) + ' Std';
			if (diff < 604800) return Math.floor(diff / 86400) + ' Tage';
			return formatDate(str);
		} catch (e) { return str; }
	}

	function truncate(text, len) {
		if (!text || text.length <= len) return text || '';
		return text.substring(0, len) + '\u2026';
	}

	function el(tag, className, textContent) {
		var node = document.createElement(tag);
		if (className) node.className = className;
		if (textContent) node.textContent = textContent;
		return node;
	}

	function materialIcon(name, style) {
		var i = el('i', 'material-icons');
		i.textContent = name;
		if (style) i.style.cssText = style;
		return i;
	}

	// ══════════════════════════════════════════════════
	// Phase 4: TOC
	// ══════════════════════════════════════════════════

	function buildTOC() {
		if (!content || !tocList) return;

		var headings = content.querySelectorAll('h1, h2, h3, h4');
		if (headings.length === 0) {
			tocPanel.classList.add('hidden');
			document.getElementById('btn-toc-toggle').style.display = 'none';
			return;
		}

		var minLevel = 6;
		headings.forEach(function (h) {
			var level = parseInt(h.tagName.charAt(1), 10);
			if (level < minLevel) minLevel = level;
		});

		headings.forEach(function (h, i) {
			if (!h.id) h.id = 'reader-heading-' + i;

			var level = parseInt(h.tagName.charAt(1), 10);
			var link = document.createElement('a');
			link.className = 'toc-link';
			link.href = '#' + h.id;
			link.setAttribute('data-level', level);
			link.setAttribute('data-heading-id', h.id);
			link.textContent = truncate(h.textContent.trim(), 60);

			link.addEventListener('click', function (e) {
				e.preventDefault();
				h.scrollIntoView({ behavior: 'smooth', block: 'start' });
				h.classList.add('reader-heading-flash');
				setTimeout(function () { h.classList.remove('reader-heading-flash'); }, 1500);
			});

			tocList.appendChild(link);
		});

		// Scroll-Sync via Intersection Observer
		var observer = new IntersectionObserver(function (entries) {
			entries.forEach(function (entry) {
				if (entry.isIntersecting) {
					var id = entry.target.id;
					tocList.querySelectorAll('.toc-link').forEach(function (link) {
						link.classList.toggle('toc-active',
							link.getAttribute('data-heading-id') === id);
					});
					var activeLink = tocList.querySelector('.toc-active');
					if (activeLink) {
						activeLink.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
					}
				}
			});
		}, {
			root: content,
			rootMargin: '-10% 0px -80% 0px',
			threshold: 0
		});

		headings.forEach(function (h) { observer.observe(h); });
	}

	// ══════════════════════════════════════════════════
	// Phase 5: Info-Tab (DOM-basiert, kein innerHTML)
	// ══════════════════════════════════════════════════

	function renderInfoTab() {
		var panel = document.getElementById('sidebar-info');
		if (!panel) return;

		while (panel.firstChild) panel.removeChild(panel.firstChild);

		// Titel
		panel.appendChild(el('div', 'info-title', meta.title || ''));

		// Domain
		if (meta.domain) {
			var domainLink = el('a', 'info-domain');
			domainLink.href = meta.link || '#';
			domainLink.target = '_blank';
			domainLink.appendChild(materialIcon('language', 'font-size:14px'));
			domainLink.appendChild(document.createTextNode(' ' + meta.domain));
			panel.appendChild(domainLink);
		}

		// Metadaten-Tabelle
		var table = el('table', 'info-meta-table');
		var tbody = document.createElement('tbody');

		function addRow(label, value) {
			if (!value) return;
			var tr = document.createElement('tr');
			var td1 = document.createElement('td');
			td1.textContent = label;
			var td2 = document.createElement('td');
			td2.textContent = value;
			tr.appendChild(td1);
			tr.appendChild(td2);
			tbody.appendChild(tr);
		}

		addRow('Feed', meta.feed_title);
		addRow('Autor', meta.author);
		addRow('Publiziert', formatDate(meta.published));
		addRow('Gespeichert', formatDate(meta.saved));
		addRow('Sprache', meta.lang);
		addRow('Wörter', String(meta.word_count || 0));
		addRow('Lesezeit', '~' + (meta.reading_time || 1) + ' Min');

		table.appendChild(tbody);
		panel.appendChild(table);

		// Labels
		if (meta.labels && meta.labels.length) {
			var labelsDiv = el('div', 'info-labels');
			meta.labels.forEach(function (label) {
				var badge = el('span', 'info-label-badge', label[1]);
				badge.style.background = label[2] || '#555';
				badge.style.color = label[3] || '#fff';
				labelsDiv.appendChild(badge);
			});
			panel.appendChild(labelsDiv);
		}

		// Original-Link
		if (meta.link) {
			var origLink = el('a', 'info-original-link');
			origLink.href = meta.link;
			origLink.target = '_blank';
			origLink.rel = 'noopener';
			origLink.appendChild(materialIcon('open_in_new', 'font-size:13px;vertical-align:middle;margin-right:4px'));
			origLink.appendChild(document.createTextNode(meta.link));
			panel.appendChild(origLink);
		}
	}

	// ══════════════════════════════════════════════════
	// Phase 6: Notizen-Tab
	// ══════════════════════════════════════════════════

	function renderNotesTab() {
		var panel = document.getElementById('sidebar-notes');
		if (!panel) return;

		while (panel.firstChild) panel.removeChild(panel.firstChild);

		if (!annotations || annotations.length === 0) {
			var empty = el('div', 'notes-empty');
			empty.appendChild(materialIcon('highlight'));
			empty.appendChild(document.createTextNode('Noch keine Annotationen'));
			var hint = el('div');
			hint.style.marginTop = '8px';
			hint.textContent = 'Text markieren, um eine Annotation zu erstellen';
			empty.appendChild(hint);
			panel.appendChild(empty);
			return;
		}

		annotations.forEach(function (ann) {
			panel.appendChild(createNoteCard(ann));
		});
	}

	function createNoteCard(ann) {
		var card = el('div', 'note-card');
		card.setAttribute('data-ann-id', ann.id);

		// Farbrand
		var colorBar = el('div', 'note-card-color');
		colorBar.style.backgroundColor = ann.color || '#fff3cd';
		card.appendChild(colorBar);

		var inner = el('div', 'note-card-inner');

		// Markierter Text
		var text = el('div', 'note-card-text note-card-highlight',
			'\u201E' + truncate(ann.highlighted_text, 120) + '\u201C');
		inner.appendChild(text);

		// Notiz
		if (ann.note) {
			inner.appendChild(el('div', 'note-card-note', ann.note));
		}

		// Marker
		if (ann.markers) {
			var markersDiv = el('div', 'note-card-markers');
			ann.markers.split(',').forEach(function (m) {
				m = m.trim();
				if (!m) return;
				markersDiv.appendChild(el('span', 'note-card-marker', m));
			});
			inner.appendChild(markersDiv);
		}

		// Footer
		var footer = el('div', 'note-card-footer');
		footer.appendChild(el('span', null, relativeTime(ann.created_at)));

		var actions = el('div', 'note-card-actions');
		var deleteBtn = el('button', 'note-card-action delete');
		deleteBtn.title = 'Löschen';
		deleteBtn.appendChild(materialIcon('close'));
		deleteBtn.addEventListener('click', function (e) {
			e.stopPropagation();
			if (confirm('Annotation löschen?')) {
				deleteAnnotation(ann.id, card);
			}
		});
		actions.appendChild(deleteBtn);
		footer.appendChild(actions);
		inner.appendChild(footer);
		card.appendChild(inner);

		// Klick → zum Highlight scrollen
		card.addEventListener('click', function () {
			scrollToAnnotation(ann.id);
		});

		return card;
	}

	function scrollToAnnotation(annId) {
		var mark = content.querySelector('mark[data-ann-id="' + annId + '"]');
		if (!mark) return;
		mark.scrollIntoView({ behavior: 'smooth', block: 'center' });
		mark.classList.add('ann-highlight-active');
		setTimeout(function () { mark.classList.remove('ann-highlight-active'); }, 2000);
	}

	function deleteAnnotation(annId, card) {
		ajax('delete_annotation', {
			plugin: 'annotations',
			id: annId
		}).then(function () {
			// Karte entfernen
			if (card) card.remove();
			// Highlight entfernen
			var mark = content.querySelector('mark[data-ann-id="' + annId + '"]');
			if (mark) {
				var parent = mark.parentNode;
				while (mark.firstChild) parent.insertBefore(mark.firstChild, mark);
				parent.removeChild(mark);
				parent.normalize();
			}
			// Aus annotations-Array entfernen
			annotations = annotations.filter(function (a) { return a.id != annId; });
			// Falls keine Annotationen mehr → Empty State
			if (annotations.length === 0) renderNotesTab();
		});
	}

	// ══════════════════════════════════════════════════
	// Phase 7: Fortschrittsbalken
	// ══════════════════════════════════════════════════

	function initProgressBar() {
		if (!content || !progressBar) return;

		content.addEventListener('scroll', function () {
			var scrollTop = content.scrollTop;
			var scrollHeight = content.scrollHeight - content.clientHeight;
			var pct = scrollHeight > 0 ? Math.min(100, (scrollTop / scrollHeight) * 100) : 0;
			progressBar.style.width = pct + '%';
		});
	}

	// ══════════════════════════════════════════════════
	// Phase 8: Toolbar, Panels, Navigation
	// ══════════════════════════════════════════════════

	// Panel-Toggle
	function initPanelToggles() {
		var tocBtn = document.getElementById('btn-toc-toggle');
		var sidebarBtn = document.getElementById('btn-sidebar-toggle');
		var backdrop = el('div', 'reader-overlay-backdrop');
		document.body.appendChild(backdrop);

		function closeOverlay() {
			backdrop.classList.remove('active');
		}

		backdrop.addEventListener('click', function () {
			tocPanel.classList.remove('force-show');
			sidebar.classList.remove('force-show');
			if (tocBtn) tocBtn.classList.remove('active');
			if (sidebarBtn) sidebarBtn.classList.remove('active');
			closeOverlay();
		});

		if (tocBtn) {
			tocBtn.addEventListener('click', function () {
				var isHidden = tocPanel.classList.contains('hidden');
				var isForceShow = tocPanel.classList.contains('force-show');

				if (isHidden && !isForceShow) {
					tocPanel.classList.remove('hidden');
					tocPanel.classList.add('force-show');
					tocBtn.classList.add('active');
					backdrop.classList.add('active');
				} else if (isForceShow) {
					tocPanel.classList.remove('force-show');
					tocPanel.classList.add('hidden');
					tocBtn.classList.remove('active');
					closeOverlay();
				} else {
					tocPanel.classList.add('hidden');
					tocBtn.classList.remove('active');
				}
			});
		}

		if (sidebarBtn) {
			sidebarBtn.addEventListener('click', function () {
				var isHidden = sidebar.classList.contains('hidden');
				var isForceShow = sidebar.classList.contains('force-show');

				if (isHidden && !isForceShow) {
					sidebar.classList.remove('hidden');
					sidebar.classList.add('force-show');
					sidebarBtn.classList.add('active');
					backdrop.classList.add('active');
				} else if (isForceShow) {
					sidebar.classList.remove('force-show');
					sidebar.classList.add('hidden');
					sidebarBtn.classList.remove('active');
					closeOverlay();
				} else {
					sidebar.classList.add('hidden');
					sidebarBtn.classList.remove('active');
				}
			});
		}
	}

	// Sidebar-Tabs
	function initSidebarTabs() {
		var tabs = document.querySelectorAll('.sidebar-tab');
		tabs.forEach(function (tab) {
			tab.addEventListener('click', function () {
				var panelId = tab.getAttribute('data-panel');
				tabs.forEach(function (t) { t.classList.remove('active'); });
				tab.classList.add('active');
				document.querySelectorAll('.sidebar-panel').forEach(function (p) {
					p.classList.toggle('active', p.id === panelId);
				});
			});
		});
	}

	// Content-Breite
	function initWidthButtons() {
		var buttons = document.querySelectorAll('.toolbar-btn-width');
		var currentWidth = content ? content.getAttribute('data-width') : 'medium';

		buttons.forEach(function (btn) {
			if (btn.getAttribute('data-width') === currentWidth) {
				btn.classList.add('active');
			}
			btn.addEventListener('click', function () {
				var width = btn.getAttribute('data-width');
				buttons.forEach(function (b) { b.classList.remove('active'); });
				btn.classList.add('active');
				content.setAttribute('data-width', width);
				saveSettings({ content_width: width });
			});
		});
	}

	// Typography-Dropdown
	function initTypography() {
		var btn = document.getElementById('btn-typography');
		var dropdown = document.getElementById('typography-dropdown');
		if (!btn || !dropdown) return;

		btn.addEventListener('click', function (e) {
			e.stopPropagation();
			var visible = dropdown.style.display !== 'none';
			dropdown.style.display = visible ? 'none' : 'block';
			btn.classList.toggle('active', !visible);
		});

		document.addEventListener('click', function (e) {
			if (!dropdown.contains(e.target) && e.target !== btn) {
				dropdown.style.display = 'none';
				btn.classList.remove('active');
			}
		});

		// Font Size
		var fontSize = document.getElementById('typo-font-size');
		var fontSizeVal = document.getElementById('typo-font-size-val');
		if (fontSize) {
			fontSize.addEventListener('input', function () {
				document.body.style.setProperty('--reader-font-size', fontSize.value + 'px');
				fontSizeVal.textContent = fontSize.value + 'px';
			});
			fontSize.addEventListener('change', function () {
				saveSettings({ font_size: fontSize.value });
			});
		}

		// Line Height
		var lineHeight = document.getElementById('typo-line-height');
		var lineHeightVal = document.getElementById('typo-line-height-val');
		if (lineHeight) {
			lineHeight.addEventListener('input', function () {
				document.body.style.setProperty('--reader-line-height', lineHeight.value);
				lineHeightVal.textContent = lineHeight.value;
			});
			lineHeight.addEventListener('change', function () {
				saveSettings({ line_height: lineHeight.value });
			});
		}

		// Paragraph Spacing
		var paraSpacing = document.getElementById('typo-paragraph-spacing');
		var paraSpacingVal = document.getElementById('typo-paragraph-spacing-val');
		if (paraSpacing) {
			paraSpacing.addEventListener('input', function () {
				document.body.style.setProperty('--reader-paragraph-spacing', paraSpacing.value + 'em');
				paraSpacingVal.textContent = paraSpacing.value + 'em';
			});
			paraSpacing.addEventListener('change', function () {
				saveSettings({ paragraph_spacing: paraSpacing.value });
			});
		}

		// Font Family
		var fontBtns = document.querySelectorAll('.typo-font-btn');
		fontBtns.forEach(function (fb) {
			fb.addEventListener('click', function () {
				fontBtns.forEach(function (b) { b.classList.remove('active'); });
				fb.classList.add('active');
				var font = fb.getAttribute('data-font');
				document.body.classList.toggle('serif-font', font === 'serif');
				saveSettings({ font_family: font });
			});
		});
	}

	// Stern-Toggle
	function initStar() {
		var btn = document.getElementById('btn-star');
		if (!btn) return;

		var marked = meta.marked;
		btn.addEventListener('click', function () {
			marked = !marked;
			var icon = btn.querySelector('.material-icons');
			icon.textContent = marked ? 'star' : 'star_border';

			var body = new URLSearchParams();
			body.append('op', 'article');
			body.append('method', 'setArticleMarked');
			body.append('csrf_token', csrfToken);
			body.append('id', meta.id);
			body.append('marked', marked ? '1' : '0');
			fetch('../../backend.php', {
				method: 'POST',
				credentials: 'same-origin',
				body: body
			});
		});
	}

	// Prev/Next Navigation
	function initNavigation() {
		var prevBtn = document.getElementById('btn-prev');
		var nextBtn = document.getElementById('btn-next');

		ajax('get_adjacent_articles', {
			id: meta.id,
			feed_id: meta.feed_id
		}).then(function (data) {
			if (data.prev_id) {
				prevBtn.disabled = false;
				prevBtn.addEventListener('click', function () {
					window.location.href = 'reader.php?id=' + data.prev_id;
				});
			}
			if (data.next_id) {
				nextBtn.disabled = false;
				nextBtn.addEventListener('click', function () {
					window.location.href = 'reader.php?id=' + data.next_id;
				});
			}
		});
	}

	// Settings speichern (debounced)
	var saveTimer = null;
	var pendingSettings = {};

	function saveSettings(partial) {
		Object.assign(pendingSettings, partial);
		clearTimeout(saveTimer);
		saveTimer = setTimeout(function () {
			var params = Object.assign({}, pendingSettings);
			// Volle Settings senden
			params.font_size = params.font_size || document.getElementById('typo-font-size').value;
			params.line_height = params.line_height || document.getElementById('typo-line-height').value;
			params.paragraph_spacing = params.paragraph_spacing || document.getElementById('typo-paragraph-spacing').value;
			params.content_width = params.content_width || content.getAttribute('data-width');
			params.font_family = params.font_family || (document.body.classList.contains('serif-font') ? 'serif' : 'sans');
			ajax('save_settings', params);
			pendingSettings = {};
		}, 500);
	}

	// ══════════════════════════════════════════════════
	// Phase 9: Annotations im Reader
	// ══════════════════════════════════════════════════

	var ANN_COLORS = ['#fff3cd', '#d4edda', '#d1ecf1', '#f8d7da', '#e2d9f3', '#fce4ec'];

	function applyHighlights() {
		if (!wrapper || !annotations || annotations.length === 0) return;

		annotations.forEach(function (ann) {
			var textContent = ann.highlighted_text;
			if (!textContent) return;

			if (wrapper.querySelector('mark[data-ann-id="' + ann.id + '"]')) return;

			var context = null;
			if (ann.selector_path) {
				try { context = JSON.parse(ann.selector_path); } catch (e) { /* ignorieren */ }
			}

			var found = false;
			var treeWalker = document.createTreeWalker(
				wrapper, NodeFilter.SHOW_TEXT, null, false
			);

			var node;
			while ((node = treeWalker.nextNode())) {
				if (node.parentElement && node.parentElement.classList &&
					(node.parentElement.classList.contains('ann-highlight') ||
					 node.parentElement.classList.contains('ann-fulltext-hint'))) continue;

				var searchFrom = 0;
				while (true) {
					var idx = node.textContent.indexOf(textContent, searchFrom);
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
						mark.dataset.annNote = ann.note || '';
						mark.dataset.annColor = ann.color || '#fff3cd';
						mark.dataset.annMarkers = ann.markers || '';
						if (ann.note) {
							mark.title = ann.note;
							mark.classList.add('ann-has-note');
						}

						var range = document.createRange();
						range.setStart(node, idx);
						range.setEnd(node, Math.min(idx + textContent.length, node.textContent.length));
						range.surroundContents(mark);
						found = true;

						// Klick-Handler für Bearbeitung
						mark.addEventListener('click', function (e) {
							e.stopPropagation();
							editHighlight(mark);
						});
					} catch (e) { /* DOM-Grenzüberschreitung */ }
					break;
				}
				if (found) break;
			}
		});
	}

	// Context-Toolbar nach Textauswahl
	function initTextSelection() {
		if (!wrapper) return;

		content.addEventListener('mouseup', function (e) {
			if (e.target.closest('.ann-popup') || e.target.closest('.ann-context-toolbar')) return;

			setTimeout(function () {
				var sel = window.getSelection();
				if (!sel || sel.isCollapsed || !sel.toString().trim()) return;

				// Prüfen ob Selection im Wrapper ist
				var range = sel.getRangeAt(0);
				if (!wrapper.contains(range.commonAncestorContainer)) return;

				showContextToolbar(sel);
			}, 10);
		});
	}

	function showContextToolbar(sel) {
		removeContextToolbar();
		removePopup();

		var range = sel.getRangeAt(0);
		var rect = range.getBoundingClientRect();
		var selectedText = sel.toString().trim();
		if (!selectedText) return;

		var beforeText = '';
		try {
			var startNode = range.startContainer;
			beforeText = startNode.textContent.substring(
				Math.max(0, range.startOffset - 20), range.startOffset);
		} catch (e) { /* ignorieren */ }

		var toolbar = el('div', 'ann-context-toolbar');

		var toolbarWidth = 168;
		var left = rect.left + (rect.width / 2) - (toolbarWidth / 2);
		left = Math.max(8, Math.min(left, window.innerWidth - toolbarWidth - 8));

		toolbar.style.position = 'fixed';
		toolbar.style.left = left + 'px';
		toolbar.style.top = (rect.top - 48) + 'px';
		toolbar.style.zIndex = '10001';

		if (rect.top - 48 < 4) {
			toolbar.style.top = (rect.bottom + 8) + 'px';
			toolbar.classList.add('ann-toolbar-below');
		}

		// Highlight-Button
		var highlightBtn = createToolbarBtn('Hervorheben',
			'M11 7h6l-7 10v-6H4l7-10v6z');
		highlightBtn.addEventListener('click', function () {
			var selectorPath = JSON.stringify({ before: beforeText });
			saveAnnotation(meta.id, selectedText, '', '#fff3cd',
				range.startOffset, range.endOffset, selectorPath, '');
			removeContextToolbar();
			window.getSelection().removeAllRanges();
		});

		// Notiz-Button
		var noteBtn = createToolbarBtn('Notiz hinzufügen',
			'M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H6l-2 2V4h16v12z');
		noteBtn.addEventListener('click', function () {
			removeContextToolbar();
			showPopup(sel);
		});

		// Marker-Button
		var tagBtn = createToolbarBtn('Marker hinzufügen',
			'M21.41 11.58l-9-9C12.05 2.22 11.55 2 11 2H4c-1.1 0-2 .9-2 2v7c0 .55.22 1.05.59 1.42l9 9c.36.36.86.58 1.41.58s1.05-.22 1.41-.59l7-7c.37-.36.59-.86.59-1.41s-.23-1.06-.59-1.42zM5.5 7C4.67 7 4 6.33 4 5.5S4.67 4 5.5 4 7 4.67 7 5.5 6.33 7 5.5 7z');
		tagBtn.addEventListener('click', function () {
			removeContextToolbar();
			showMarkerInput(sel, beforeText);
		});

		toolbar.appendChild(highlightBtn);
		toolbar.appendChild(noteBtn);
		toolbar.appendChild(tagBtn);
		document.body.appendChild(toolbar);

		setTimeout(function () {
			document.addEventListener('mousedown', function handler(e) {
				if (!e.target.closest('.ann-context-toolbar') && !e.target.closest('.ann-popup')) {
					removeContextToolbar();
					document.removeEventListener('mousedown', handler, true);
				}
			}, true);
		}, 100);
	}

	function createToolbarBtn(title, pathD) {
		var btn = el('button', 'ann-toolbar-btn');
		btn.title = title;
		var ns = 'http://www.w3.org/2000/svg';
		var svg = document.createElementNS(ns, 'svg');
		svg.setAttribute('viewBox', '0 0 24 24');
		svg.setAttribute('width', '18');
		svg.setAttribute('height', '18');
		svg.setAttribute('fill', 'currentColor');
		var path = document.createElementNS(ns, 'path');
		path.setAttribute('d', pathD);
		svg.appendChild(path);
		btn.appendChild(svg);
		return btn;
	}

	function removeContextToolbar() {
		document.querySelectorAll('.ann-context-toolbar').forEach(function (t) { t.remove(); });
	}

	function removePopup() {
		document.querySelectorAll('.ann-popup').forEach(function (p) { p.remove(); });
	}

	// Vollständiges Popup mit Farbe, Notiz, Marker
	function showPopup(sel) {
		removePopup();

		var range = sel.getRangeAt(0);
		var rect = range.getBoundingClientRect();
		var selectedText = sel.toString().trim();
		if (!selectedText) return;

		var beforeText = '';
		try {
			var startNode = range.startContainer;
			beforeText = startNode.textContent.substring(
				Math.max(0, range.startOffset - 20), range.startOffset);
		} catch (e) { /* ignorieren */ }

		var popup = el('div', 'ann-popup');
		popup.style.position = 'fixed';
		popup.style.left = Math.min(rect.left, window.innerWidth - 300) + 'px';
		popup.style.top = (rect.bottom + 5) + 'px';
		popup.style.zIndex = '10000';

		// Vorschau
		popup.appendChild(el('div', 'ann-popup-preview', truncate(selectedText, 80)));

		// Farben
		var colorRow = el('div', 'ann-color-row');
		ANN_COLORS.forEach(function (color) {
			var btn = el('span', 'ann-color-btn');
			btn.style.backgroundColor = color;
			btn.dataset.color = color;
			btn.addEventListener('click', function () {
				colorRow.querySelectorAll('.ann-color-btn').forEach(function (b) {
					b.classList.remove('ann-color-active');
				});
				btn.classList.add('ann-color-active');
			});
			colorRow.appendChild(btn);
		});
		if (colorRow.firstChild) colorRow.firstChild.classList.add('ann-color-active');

		// Notiz
		var noteInput = el('textarea', 'ann-note-input');
		noteInput.placeholder = 'Notiz hinzufügen (optional)...';
		noteInput.rows = 2;

		// Marker
		var markersContainer = el('div');
		var getMarkers = createMarkersInput(markersContainer, '');

		// Buttons
		var btnRow = el('div', 'ann-btn-row');

		var saveBtn = el('button', 'ann-save-btn', 'Speichern');
		saveBtn.addEventListener('click', function () {
			var activeColor = popup.querySelector('.ann-color-active');
			var color = activeColor ? activeColor.dataset.color : '#fff3cd';
			var selectorPath = JSON.stringify({ before: beforeText });
			saveAnnotation(meta.id, selectedText, noteInput.value, color,
				range.startOffset, range.endOffset, selectorPath, getMarkers());
			removePopup();
			window.getSelection().removeAllRanges();
		});

		var cancelBtn = el('button', 'ann-cancel-btn', 'Abbrechen');
		cancelBtn.addEventListener('click', function () { removePopup(); });

		btnRow.appendChild(saveBtn);
		btnRow.appendChild(cancelBtn);

		popup.appendChild(colorRow);
		popup.appendChild(noteInput);
		popup.appendChild(markersContainer);
		popup.appendChild(btnRow);
		document.body.appendChild(popup);

		setTimeout(function () {
			document.addEventListener('mousedown', function handler(e) {
				if (!popup.contains(e.target)) {
					removePopup();
					document.removeEventListener('mousedown', handler, true);
				}
			}, true);
		}, 200);
	}

	// Marker-Schnelleingabe
	function showMarkerInput(sel, beforeText) {
		removePopup();

		var range = sel.getRangeAt(0);
		var rect = range.getBoundingClientRect();
		var selectedText = sel.toString().trim();
		if (!selectedText) return;

		var popup = el('div', 'ann-popup ann-tag-popup');
		popup.style.position = 'fixed';
		popup.style.left = Math.min(rect.left, window.innerWidth - 280) + 'px';
		popup.style.top = (rect.top - 70) + 'px';
		popup.style.zIndex = '10001';

		if (rect.top - 70 < 4) {
			popup.style.top = (rect.bottom + 8) + 'px';
		}

		popup.appendChild(el('div', 'ann-tag-label', 'Marker hinzufügen'));

		var inputRow = el('div', 'ann-tag-input-row');

		var input = el('input', 'ann-tag-input');
		input.type = 'text';
		input.placeholder = 'Marker eingeben...';
		input.addEventListener('keydown', function (e) {
			if (e.key === 'Enter' && input.value.trim()) {
				doSave();
			} else if (e.key === 'Escape') {
				removePopup();
			}
		});

		var addBtn = el('button', 'ann-save-btn', 'Speichern');
		addBtn.addEventListener('click', function () {
			if (input.value.trim()) doSave();
		});

		inputRow.appendChild(input);
		inputRow.appendChild(addBtn);
		popup.appendChild(inputRow);
		document.body.appendChild(popup);

		setTimeout(function () { input.focus(); }, 50);

		function doSave() {
			var markers = input.value.trim();
			var selectorPath = JSON.stringify({ before: beforeText });
			saveAnnotation(meta.id, selectedText, '', '#fff3cd',
				range.startOffset, range.endOffset, selectorPath, markers);
			removePopup();
			window.getSelection().removeAllRanges();
		}

		setTimeout(function () {
			document.addEventListener('mousedown', function handler(e) {
				if (!popup.contains(e.target)) {
					removePopup();
					document.removeEventListener('mousedown', handler, true);
				}
			}, true);
		}, 200);
	}

	// Marker-Chips-Input (für Popup)
	function createMarkersInput(container, initial) {
		var currentMarkers = initial ? initial.split(',').map(function (m) { return m.trim(); }).filter(Boolean) : [];

		var wrap = el('div', 'ann-markers-wrapper');
		wrap.appendChild(el('div', 'ann-markers-label', 'Marker'));

		var chips = el('div', 'ann-markers-chips');
		wrap.appendChild(chips);

		var input = el('input', 'ann-markers-input');
		input.type = 'text';
		input.placeholder = 'Marker eingeben...';
		wrap.appendChild(input);

		function renderChips() {
			while (chips.firstChild) chips.removeChild(chips.firstChild);
			currentMarkers.forEach(function (m, i) {
				var chip = el('span', 'ann-marker-chip', m);
				var remove = el('span', 'ann-marker-chip-remove', '\u00d7');
				remove.addEventListener('click', function () {
					currentMarkers.splice(i, 1);
					renderChips();
				});
				chip.appendChild(remove);
				chips.appendChild(chip);
			});
		}

		input.addEventListener('keydown', function (e) {
			if ((e.key === 'Enter' || e.key === ',') && input.value.trim()) {
				e.preventDefault();
				var val = input.value.trim().replace(/,/g, '');
				if (val && currentMarkers.indexOf(val) === -1) {
					currentMarkers.push(val);
					renderChips();
				}
				input.value = '';
			} else if (e.key === 'Backspace' && !input.value && currentMarkers.length) {
				currentMarkers.pop();
				renderChips();
			}
		});

		renderChips();
		container.appendChild(wrap);

		return function () {
			return currentMarkers.join(',');
		};
	}

	// Annotation speichern (via Backend)
	function saveAnnotation(articleId, text, note, color, startOffset, endOffset, selectorPath, markers) {
		ajax('save_annotation', {
			plugin: 'annotations',
			ref_id: articleId,
			highlighted_text: text,
			note: note,
			color: color,
			start_offset: startOffset,
			end_offset: endOffset,
			selector_path: selectorPath,
			markers: markers || ''
		}).then(function (reply) {
			if (reply && reply.status === 'ok') {
				var newAnn = {
					id: reply.id,
					highlighted_text: text,
					note: note,
					color: color,
					selector_path: selectorPath,
					markers: markers || '',
					created_at: new Date().toISOString()
				};
				annotations.push(newAnn);

				// Highlight im DOM anwenden
				applyHighlights();

				// Karte im Notizen-Tab hinzufügen
				var panel = document.getElementById('sidebar-notes');
				if (panel) {
					var empty = panel.querySelector('.notes-empty');
					if (empty) empty.remove();
					panel.appendChild(createNoteCard(newAnn));
				}
			}
		});
	}

	// Bestehendes Highlight bearbeiten
	function editHighlight(mark) {
		removePopup();

		var annId = mark.dataset.annId;
		var currentNote = mark.dataset.annNote || '';
		var currentColor = mark.dataset.annColor || '#fff3cd';
		var currentMarkers = mark.dataset.annMarkers || '';

		var rect = mark.getBoundingClientRect();

		var popup = el('div', 'ann-popup');
		popup.style.position = 'fixed';
		popup.style.left = Math.min(rect.left, window.innerWidth - 300) + 'px';
		popup.style.top = (rect.bottom + 5) + 'px';
		popup.style.zIndex = '10000';

		// Vorschau
		popup.appendChild(el('div', 'ann-popup-preview', truncate(mark.textContent, 80)));

		// Farben
		var colorRow = el('div', 'ann-color-row');
		ANN_COLORS.forEach(function (color) {
			var btn = el('span', 'ann-color-btn');
			btn.style.backgroundColor = color;
			btn.dataset.color = color;
			if (color === currentColor) btn.classList.add('ann-color-active');
			btn.addEventListener('click', function () {
				colorRow.querySelectorAll('.ann-color-btn').forEach(function (b) {
					b.classList.remove('ann-color-active');
				});
				btn.classList.add('ann-color-active');
			});
			colorRow.appendChild(btn);
		});

		// Notiz
		var noteInput = el('textarea', 'ann-note-input');
		noteInput.placeholder = 'Notiz hinzufügen (optional)...';
		noteInput.rows = 2;
		noteInput.value = currentNote;

		// Marker
		var markersContainer = el('div');
		var getMarkers = createMarkersInput(markersContainer, currentMarkers);

		// Buttons
		var btnRow = el('div', 'ann-btn-row');

		var deleteBtn = el('button', 'ann-delete-btn', 'Löschen');
		deleteBtn.addEventListener('click', function () {
			if (confirm('Annotation löschen?')) {
				var card = document.querySelector('.note-card[data-ann-id="' + annId + '"]');
				deleteAnnotation(annId, card);
				removePopup();
			}
		});

		var saveBtn = el('button', 'ann-save-btn', 'Speichern');
		saveBtn.addEventListener('click', function () {
			var activeColor = popup.querySelector('.ann-color-active');
			var color = activeColor ? activeColor.dataset.color : currentColor;
			var note = noteInput.value;
			var markers = getMarkers();

			ajax('update_annotation', {
				plugin: 'annotations',
				id: annId,
				note: note,
				color: color,
				markers: markers
			}).then(function (reply) {
				if (reply && reply.status === 'ok') {
					mark.style.backgroundColor = color;
					mark.dataset.annNote = note;
					mark.dataset.annColor = color;
					mark.dataset.annMarkers = markers;
					mark.title = note || '';
					mark.classList.toggle('ann-has-note', !!note);

					// Annotation im Array aktualisieren
					annotations.forEach(function (a) {
						if (a.id == annId) {
							a.note = note;
							a.color = color;
							a.markers = markers;
						}
					});
					renderNotesTab();
				}
			});
			removePopup();
		});

		var cancelBtn = el('button', 'ann-cancel-btn', 'Abbrechen');
		cancelBtn.addEventListener('click', function () { removePopup(); });

		btnRow.appendChild(deleteBtn);
		btnRow.appendChild(saveBtn);
		btnRow.appendChild(cancelBtn);

		popup.appendChild(colorRow);
		popup.appendChild(noteInput);
		popup.appendChild(markersContainer);
		popup.appendChild(btnRow);
		document.body.appendChild(popup);

		setTimeout(function () {
			document.addEventListener('mousedown', function handler(e) {
				if (!popup.contains(e.target)) {
					removePopup();
					document.removeEventListener('mousedown', handler, true);
				}
			}, true);
		}, 200);
	}

	// ══════════════════════════════════════════════════
	// Keyboard Shortcuts
	// ══════════════════════════════════════════════════

	function initKeyboardShortcuts() {
		document.addEventListener('keydown', function (e) {
			if (e.target.matches('input, textarea, select, [contenteditable]')) return;
			if (e.ctrlKey || e.altKey || e.metaKey) return;

			switch (e.key) {
				case 'Escape':
					if (document.querySelector('.ann-popup') || document.querySelector('.ann-context-toolbar')) {
						removePopup();
						removeContextToolbar();
					} else {
						history.back();
					}
					break;
				case 'j':
					e.preventDefault();
					var nextBtn = document.getElementById('btn-next');
					if (nextBtn && !nextBtn.disabled) nextBtn.click();
					break;
				case 'k':
					e.preventDefault();
					var prevBtn = document.getElementById('btn-prev');
					if (prevBtn && !prevBtn.disabled) prevBtn.click();
					break;
				case 't':
					e.preventDefault();
					var tocToggle = document.getElementById('btn-toc-toggle');
					if (tocToggle && tocToggle.style.display !== 'none') tocToggle.click();
					break;
				case 'i':
					e.preventDefault();
					var sidebarToggle = document.getElementById('btn-sidebar-toggle');
					if (sidebarToggle) sidebarToggle.click();
					break;
				case '1':
					setWidthByKey('narrow');
					break;
				case '2':
					setWidthByKey('medium');
					break;
				case '3':
					setWidthByKey('wide');
					break;
				case 's':
					e.preventDefault();
					var starBtn = document.getElementById('btn-star');
					if (starBtn) starBtn.click();
					break;
			}
		});
	}

	function setWidthByKey(width) {
		var btn = document.querySelector('.toolbar-btn-width[data-width="' + width + '"]');
		if (btn) btn.click();
	}

	// ══════════════════════════════════════════════════
	// Init
	// ══════════════════════════════════════════════════

	buildTOC();
	renderInfoTab();
	applyHighlights();
	renderNotesTab();
	initProgressBar();
	initPanelToggles();
	initSidebarTabs();
	initWidthButtons();
	initTypography();
	initStar();
	initNavigation();
	initTextSelection();
	initKeyboardShortcuts();

})();
