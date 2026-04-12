/**
 * TT-SS Status-Bar — Fixierte Leiste am oberen Rand der Webseite.
 * Shadow DOM Container für CSS-Isolation.
 */
var TTSS = window.TTSS || {};

TTSS.statusBar = (function () {
	'use strict';

	var _host = null;
	var _shadow = null;
	var _bar = null;
	var _state = null;
	var _serverUrl = '';
	var _collapsed = false;
	var _outsideClickHandlers = [];
	var BAR_HEIGHT = 38;

	// SVG-Icons als Strings
	var ICONS = {
		reader: '<svg viewBox="0 0 24 24" width="16" height="16"><path fill="currentColor" d="M21 5c-1.11-.35-2.33-.5-3.5-.5-1.95 0-4.05.4-5.5 1.5-1.45-1.1-3.55-1.5-5.5-1.5S2.45 4.9 1 6v14.65c0 .25.25.5.5.5.1 0 .15-.05.25-.05C3.1 20.45 5.05 20 6.5 20c1.95 0 4.05.4 5.5 1.5 1.35-.85 3.8-1.5 5.5-1.5 1.65 0 3.35.3 4.75 1.05.1.05.15.05.25.05.25 0 .5-.25.5-.5V6c-.6-.45-1.25-.75-2-1z"/></svg>',
		note: '<svg viewBox="0 0 24 24" width="16" height="16"><path fill="currentColor" d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>',
		bookmark: '<svg viewBox="0 0 24 24" width="16" height="16"><path fill="currentColor" d="M17 3H7c-1.1 0-1.99.9-1.99 2L5 21l7-3 7 3V5c0-1.1-.9-2-2-2z"/></svg>',
		bookmarkBorder: '<svg viewBox="0 0 24 24" width="16" height="16"><path fill="currentColor" d="M17 3H7c-1.1 0-1.99.9-1.99 2L5 21l7-3 7 3V5c0-1.1-.9-2-2-2zm0 15l-5-2.18L7 18V5h10v13z"/></svg>'
	};

	function getStylesheet() {
		var css = '';
		css += '* { box-sizing: border-box; margin: 0; padding: 0; }';
		css += '.ttss-bar {';
		css += '  position: fixed; top: 0; left: 0; right: 0;';
		css += '  height: ' + BAR_HEIGHT + 'px;';
		css += '  background: #1a1a1a; border-bottom: 1px solid #333;';
		css += '  display: flex; align-items: center; padding: 0 12px; gap: 12px;';
		css += '  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;';
		css += '  font-size: 13px; color: #e0e0e0; z-index: 2147483646;';
		css += '  pointer-events: auto; transition: transform 0.2s;';
		css += '}';
		css += '.ttss-bar.collapsed { transform: translateY(-100%); }';
		css += '.ttss-bar-section { display: flex; align-items: center; gap: 8px; }';
		css += '.ttss-bar-divider { width: 1px; height: 20px; background: #444; }';
		css += '.ttss-bar-spacer { flex: 1; }';
		css += '.ttss-open-link { display: flex; align-items: center; gap: 4px; color: #4fc3f7; text-decoration: none; font-weight: 500; white-space: nowrap; }';
		css += '.ttss-open-link:hover { color: #81d4fa; text-decoration: underline; }';
		css += '.ttss-hl-count { background: #333; border-radius: 10px; padding: 2px 8px; font-size: 12px; color: #aaa; min-width: 20px; text-align: center; }';
		css += '.ttss-toggle { display: flex; align-items: center; gap: 4px; cursor: pointer; color: #999; font-size: 12px; white-space: nowrap; }';
		css += '.ttss-toggle.active { color: #4fc3f7; }';
		css += '.ttss-toggle-switch { width: 28px; height: 16px; border-radius: 8px; background: #555; position: relative; transition: background 0.2s; }';
		css += '.ttss-toggle.active .ttss-toggle-switch { background: #4fc3f7; }';
		css += '.ttss-toggle-knob { width: 12px; height: 12px; border-radius: 50%; background: #fff; position: absolute; top: 2px; left: 2px; transition: left 0.2s; }';
		css += '.ttss-toggle.active .ttss-toggle-knob { left: 14px; }';
		css += '.ttss-label-pill { display: inline-flex; align-items: center; gap: 3px; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: 500; white-space: nowrap; cursor: default; }';
		css += '.ttss-label-x { cursor: pointer; opacity: 0.6; font-size: 14px; line-height: 1; }';
		css += '.ttss-label-x:hover { opacity: 1; }';
		css += '.ttss-label-add { cursor: pointer; background: #333; border: 1px dashed #555; color: #999; padding: 2px 8px; border-radius: 12px; font-size: 11px; white-space: nowrap; }';
		css += '.ttss-label-add:hover { border-color: #4fc3f7; color: #4fc3f7; }';
		css += '.ttss-label-picker { position: absolute; top: ' + BAR_HEIGHT + 'px; background: #1e1e1e; border: 1px solid #444; border-radius: 6px; padding: 8px; box-shadow: 0 4px 16px rgba(0,0,0,0.4); max-height: 240px; overflow-y: auto; min-width: 180px; z-index: 2147483647; }';
		css += '.ttss-label-picker-item { display: flex; align-items: center; gap: 8px; padding: 4px 8px; cursor: pointer; border-radius: 4px; }';
		css += '.ttss-label-picker-item:hover { background: rgba(255,255,255,0.08); }';
		css += '.ttss-label-picker-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }';
		css += '.ttss-label-picker-check { font-size: 14px; color: #4fc3f7; width: 16px; text-align: center; }';
		css += '.ttss-bar-btn { cursor: pointer; padding: 4px 8px; border-radius: 4px; display: flex; align-items: center; gap: 4px; font-size: 12px; color: #999; white-space: nowrap; border: none; background: none; font-family: inherit; }';
		css += '.ttss-bar-btn:hover { background: rgba(255,255,255,0.08); color: #e0e0e0; }';
		css += '.ttss-bar-btn.active { color: #4fc3f7; }';
		css += '.ttss-note-editor { position: absolute; top: ' + BAR_HEIGHT + 'px; right: 80px; background: #1e1e1e; border: 1px solid #444; border-radius: 6px; padding: 12px; box-shadow: 0 4px 16px rgba(0,0,0,0.4); min-width: 280px; z-index: 2147483647; }';
		css += '.ttss-note-editor textarea { width: 100%; min-height: 80px; border: 1px solid #555; border-radius: 4px; padding: 8px; font-size: 12px; color: #e0e0e0; background: #2a2a2a; font-family: inherit; resize: vertical; box-sizing: border-box; margin-bottom: 8px; }';
		css += '.ttss-note-editor textarea:focus { outline: none; border-color: #4fc3f7; }';
		css += '.ttss-note-editor-btns { display: flex; justify-content: flex-end; gap: 6px; }';
		css += '.ttss-note-editor-btns button { padding: 4px 12px; border-radius: 4px; border: 1px solid #555; cursor: pointer; font-size: 12px; color: #e0e0e0; background: transparent; font-family: inherit; }';
		css += '.ttss-note-editor-btns button:hover { background: rgba(255,255,255,0.1); }';
		css += '.ttss-note-editor-btns button.primary { background: #4fc3f7; color: #111; border-color: #4fc3f7; }';
		css += '.ttss-collapse-btn { cursor: pointer; padding: 2px; color: #666; font-size: 18px; line-height: 1; transition: color 0.15s; }';
		css += '.ttss-collapse-btn:hover { color: #aaa; }';
		css += '.ttss-expand-tab { position: fixed; top: 0; right: 20px; background: #1a1a1a; border: 1px solid #333; border-top: none; border-radius: 0 0 6px 6px; padding: 2px 10px 4px; cursor: pointer; color: #666; font-size: 16px; z-index: 2147483646; pointer-events: auto; display: none; }';
		css += '.ttss-expand-tab:hover { color: #4fc3f7; }';
		return css;
	}

	/**
	 * DOM-Element sicher erstellen mit SVG-Icon (Parser statt innerHTML).
	 */
	function createIconElement(svgString) {
		var parser = new DOMParser();
		var doc = parser.parseFromString(svgString, 'image/svg+xml');
		return doc.documentElement;
	}

	function createDivider() {
		var d = document.createElement('div');
		d.className = 'ttss-bar-divider';
		return d;
	}

	/**
	 * Status-Bar anzeigen.
	 */
	function show(state, serverUrl) {
		_state = state;
		_serverUrl = serverUrl;

		if (_host) {
			_host.remove();
			_host = null;
			_shadow = null;
		}

		_host = document.createElement('div');
		_host.className = 'ttss-status-bar-host';
		_host.style.cssText = 'position:fixed;z-index:2147483646;top:0;left:0;right:0;pointer-events:none;';
		document.body.appendChild(_host);
		_shadow = _host.attachShadow({ mode: 'open' });

		var style = document.createElement('style');
		style.textContent = getStylesheet();
		_shadow.appendChild(style);

		_bar = document.createElement('div');
		_bar.className = 'ttss-bar';
		render();
		_shadow.appendChild(_bar);

		// Expand-Tab (sichtbar wenn eingeklappt)
		var expandTab = document.createElement('div');
		expandTab.className = 'ttss-expand-tab';
		expandTab.textContent = '\u25BC';
		expandTab.title = 'TT-SS Reader einblenden';
		expandTab.addEventListener('click', toggleCollapse);
		_shadow.appendChild(expandTab);

		// Seite nach unten verschieben
		document.documentElement.style.marginTop = BAR_HEIGHT + 'px';
	}

	/**
	 * Status-Bar ausblenden.
	 */
	function hide() {
		cleanupOutsideClickHandlers();
		if (_host) {
			_host.remove();
			_host = null;
			_shadow = null;
			_bar = null;
		}
		document.documentElement.style.marginTop = '';
	}

	/**
	 * Status-Bar ein-/ausklappen.
	 */
	function toggleCollapse() {
		_collapsed = !_collapsed;
		if (_bar) {
			_bar.classList.toggle('collapsed', _collapsed);
		}
		var expandTab = _shadow ? _shadow.querySelector('.ttss-expand-tab') : null;
		if (expandTab) {
			expandTab.style.display = _collapsed ? 'block' : 'none';
			expandTab.textContent = _collapsed ? '\u25BC' : '\u25B2';
		}
		document.documentElement.style.marginTop = _collapsed ? '' : BAR_HEIGHT + 'px';
	}

	/**
	 * Bar-Inhalt rendern.
	 */
	function render() {
		if (!_bar || !_state) return;

		// Bar leeren
		while (_bar.firstChild) {
			_bar.removeChild(_bar.firstChild);
		}

		// 1. "In Reader öffnen"
		var openLink = document.createElement('a');
		openLink.className = 'ttss-open-link';
		openLink.href = _serverUrl + '/#/article/' + _state.ref_id;
		openLink.target = '_blank';
		openLink.rel = 'noopener';
		openLink.appendChild(createIconElement(ICONS.reader));
		openLink.appendChild(document.createTextNode(' In Reader \u00F6ffnen \u203A'));
		_bar.appendChild(openLink);

		// 2. Highlight-Zähler
		var hlCount = document.createElement('span');
		hlCount.className = 'ttss-hl-count';
		hlCount.id = 'ttss-hl-count';
		hlCount.textContent = String(_state.annotation_count || 0);
		_bar.appendChild(hlCount);

		_bar.appendChild(createDivider());

		// 3. Auto-Highlighting Toggle
		var autoHl = document.createElement('div');
		autoHl.className = 'ttss-toggle active';
		autoHl.id = 'ttss-auto-hl';

		var toggleSwitch = document.createElement('div');
		toggleSwitch.className = 'ttss-toggle-switch';
		var toggleKnob = document.createElement('div');
		toggleKnob.className = 'ttss-toggle-knob';
		toggleSwitch.appendChild(toggleKnob);
		autoHl.appendChild(toggleSwitch);
		autoHl.appendChild(document.createTextNode(' Auto highlighting'));

		autoHl.addEventListener('click', function () {
			autoHl.classList.toggle('active');
			if (autoHl.classList.contains('active')) {
				if (TTSS.controller) TTSS.controller.loadAnnotations();
			} else {
				if (TTSS.highlighter) TTSS.highlighter.removeAll();
			}
		});
		_bar.appendChild(autoHl);

		_bar.appendChild(createDivider());

		// 4. Labels
		var labelsSection = document.createElement('div');
		labelsSection.className = 'ttss-bar-section';
		labelsSection.id = 'ttss-labels-section';
		renderLabels(labelsSection);
		_bar.appendChild(labelsSection);

		// Spacer
		var spacer = document.createElement('div');
		spacer.className = 'ttss-bar-spacer';
		_bar.appendChild(spacer);

		// 5. Seitennotiz
		var noteBtn = document.createElement('button');
		noteBtn.className = 'ttss-bar-btn' + (_state.page_note ? ' active' : '');
		noteBtn.appendChild(createIconElement(ICONS.note));
		noteBtn.appendChild(document.createTextNode(' Notiz'));
		noteBtn.addEventListener('click', toggleNoteEditor);
		_bar.appendChild(noteBtn);

		_bar.appendChild(createDivider());

		// 6. Später lesen
		var rlBtn = document.createElement('button');
		rlBtn.className = 'ttss-bar-btn' + (_state.read_later ? ' active' : '');
		rlBtn.id = 'ttss-read-later-btn';
		rlBtn.appendChild(createIconElement(_state.read_later ? ICONS.bookmark : ICONS.bookmarkBorder));
		rlBtn.appendChild(document.createTextNode(' Sp\u00E4ter lesen'));
		rlBtn.addEventListener('click', function () {
			TTSS.api.toggleReadLater(location.href).then(function (result) {
				if (result.status === 'ok') {
					_state.read_later = result.read_later;
					// Button neu rendern
					while (rlBtn.firstChild) rlBtn.removeChild(rlBtn.firstChild);
					rlBtn.className = 'ttss-bar-btn' + (result.read_later ? ' active' : '');
					rlBtn.appendChild(createIconElement(result.read_later ? ICONS.bookmark : ICONS.bookmarkBorder));
					rlBtn.appendChild(document.createTextNode(' Sp\u00E4ter lesen'));
				}
			});
		});
		_bar.appendChild(rlBtn);

		_bar.appendChild(createDivider());

		// 7. Einklappen
		var collapseBtn = document.createElement('span');
		collapseBtn.className = 'ttss-collapse-btn';
		collapseBtn.textContent = '\u25B2';
		collapseBtn.title = 'Einklappen';
		collapseBtn.addEventListener('click', toggleCollapse);
		_bar.appendChild(collapseBtn);
	}

	function cleanupOutsideClickHandlers() {
		_outsideClickHandlers.forEach(function (h) {
			document.removeEventListener('mousedown', h, true);
		});
		_outsideClickHandlers = [];
	}

	function renderLabels(container) {
		while (container.firstChild) {
			container.removeChild(container.firstChild);
		}

		(_state.labels || []).forEach(function (label) {
			var pill = document.createElement('span');
			pill.className = 'ttss-label-pill';
			pill.style.backgroundColor = label.bg_color || '#555';
			pill.style.color = label.fg_color || '#fff';
			pill.textContent = label.caption;

			var x = document.createElement('span');
			x.className = 'ttss-label-x';
			x.textContent = '\u00D7';
			x.addEventListener('click', function (e) {
				e.stopPropagation();
				removeLabel(label.id);
			});
			pill.appendChild(x);
			container.appendChild(pill);
		});

		// + Label Button
		var addBtn = document.createElement('span');
		addBtn.className = 'ttss-label-add';
		addBtn.textContent = '+ Label';
		addBtn.addEventListener('click', function () {
			toggleLabelPicker(container);
		});
		container.appendChild(addBtn);
	}

	function removeLabel(labelId) {
		var currentIds = (_state.labels || []).map(function (l) { return l.id; });
		currentIds = currentIds.filter(function (id) { return id !== labelId; });
		TTSS.api.setLabels(location.href, currentIds).then(function () {
			_state.labels = _state.labels.filter(function (l) { return l.id !== labelId; });
			var section = _shadow.getElementById('ttss-labels-section');
			if (section) renderLabels(section);
		});
	}

	function toggleLabelPicker(container) {
		var existing = _shadow.querySelector('.ttss-label-picker');
		if (existing) { existing.remove(); cleanupOutsideClickHandlers(); return; }

		TTSS.api.getLabels().then(function (allLabels) {
			var picker = document.createElement('div');
			picker.className = 'ttss-label-picker';

			var currentIds = (_state.labels || []).map(function (l) { return l.id; });

			allLabels.forEach(function (label) {
				var item = document.createElement('div');
				item.className = 'ttss-label-picker-item';

				var check = document.createElement('span');
				check.className = 'ttss-label-picker-check';
				check.textContent = currentIds.indexOf(label.id) !== -1 ? '\u2713' : '';

				var dot = document.createElement('span');
				dot.className = 'ttss-label-picker-dot';
				dot.style.backgroundColor = label.bg_color || '#555';

				var name = document.createElement('span');
				name.textContent = label.caption;

				item.appendChild(check);
				item.appendChild(dot);
				item.appendChild(name);

				item.addEventListener('click', function () {
					var idx = currentIds.indexOf(label.id);
					if (idx !== -1) {
						currentIds.splice(idx, 1);
						check.textContent = '';
					} else {
						currentIds.push(label.id);
						check.textContent = '\u2713';
					}
					TTSS.api.setLabels(location.href, currentIds).then(function () {
						_state.labels = allLabels.filter(function (l) {
							return currentIds.indexOf(l.id) !== -1;
						});
						var section = _shadow.getElementById('ttss-labels-section');
						if (section) renderLabels(section);
					});
				});

				picker.appendChild(item);
			});

			_shadow.appendChild(picker);

			// Positionierung
			var rect = container.getBoundingClientRect();
			picker.style.left = rect.left + 'px';

			// Click-Outside
			setTimeout(function () {
				var handler = function (e) {
					var path = e.composedPath();
					if (!path.some(function (el) { return el === picker || el === _host; })) {
						picker.remove();
						document.removeEventListener('mousedown', handler, true);
						_outsideClickHandlers = _outsideClickHandlers.filter(function (h) { return h !== handler; });
					}
				};
				_outsideClickHandlers.push(handler);
				document.addEventListener('mousedown', handler, true);
			}, 100);
		});
	}

	function toggleNoteEditor() {
		var existing = _shadow.querySelector('.ttss-note-editor');
		if (existing) { existing.remove(); cleanupOutsideClickHandlers(); return; }

		var editor = document.createElement('div');
		editor.className = 'ttss-note-editor';

		var textarea = document.createElement('textarea');
		textarea.value = _state.page_note || '';
		textarea.placeholder = 'Notiz zur Seite\u2026';
		editor.appendChild(textarea);

		var btns = document.createElement('div');
		btns.className = 'ttss-note-editor-btns';

		var saveBtn = document.createElement('button');
		saveBtn.className = 'primary';
		saveBtn.textContent = 'Speichern';
		saveBtn.addEventListener('click', function () {
			var note = textarea.value;
			TTSS.api.setPageNote(location.href, note).then(function () {
				_state.page_note = note;
				editor.remove();
				render();
			});
		});

		var cancelBtn = document.createElement('button');
		cancelBtn.textContent = 'Abbrechen';
		cancelBtn.addEventListener('click', function () { editor.remove(); });

		btns.appendChild(saveBtn);
		btns.appendChild(cancelBtn);
		editor.appendChild(btns);

		_shadow.appendChild(editor);
		textarea.focus();

		// Click-Outside
		setTimeout(function () {
			var handler = function (e) {
				var path = e.composedPath();
				if (!path.some(function (el) { return el === editor || el === _host; })) {
					editor.remove();
					document.removeEventListener('mousedown', handler, true);
					_outsideClickHandlers = _outsideClickHandlers.filter(function (h) { return h !== handler; });
				}
			};
			_outsideClickHandlers.push(handler);
			document.addEventListener('mousedown', handler, true);
		}, 100);
	}

	/**
	 * Highlight-Zähler aktualisieren.
	 */
	function updateHighlightCount(count) {
		if (!_shadow) return;
		var el = _shadow.getElementById('ttss-hl-count');
		if (el) el.textContent = String(count);
		if (_state) _state.annotation_count = count;
	}

	return {
		show: show,
		hide: hide,
		render: render,
		updateHighlightCount: updateHighlightCount
	};
})();
