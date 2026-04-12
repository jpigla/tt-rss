/**
 * TT-SS Highlight-Popup — Farbwahl, Notizen, Bearbeiten/Löschen.
 * Shadow DOM Container zur Vermeidung von CSS-Konflikten.
 */
var TTSS = window.TTSS || {};

TTSS.popup = (function () {
	'use strict';

	var _host = null;
	var _shadow = null;
	var _outsideClickHandler = null;

	function ensureHost() {
		if (_host) return;
		_host = document.createElement('div');
		_host.className = 'ttss-popup-host';
		_host.style.cssText = 'position:fixed;z-index:2147483647;top:0;left:0;width:0;height:0;pointer-events:none;';
		document.body.appendChild(_host);
		_shadow = _host.attachShadow({ mode: 'open' });

		var style = document.createElement('style');
		style.textContent = getStyles();
		_shadow.appendChild(style);
	}

	function getStyles() {
		return '\
.ttss-popup {\
	position: fixed;\
	background: #1e1e1e;\
	border: 1px solid #444;\
	border-radius: 8px;\
	padding: 12px;\
	box-shadow: 0 8px 24px rgba(0,0,0,0.4);\
	min-width: 260px;\
	max-width: 360px;\
	color: #e0e0e0;\
	font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;\
	font-size: 13px;\
	pointer-events: auto;\
	z-index: 2147483647;\
}\
.ttss-popup-preview {\
	font-size: 12px;\
	color: #999;\
	font-style: italic;\
	margin-bottom: 10px;\
	padding: 6px 8px;\
	background: rgba(255,255,255,0.06);\
	border-radius: 4px;\
	line-height: 1.4;\
	max-height: 60px;\
	overflow: hidden;\
}\
.ttss-color-row {\
	display: flex;\
	gap: 8px;\
	margin-bottom: 10px;\
}\
.ttss-color-btn {\
	display: inline-block;\
	width: 26px;\
	height: 26px;\
	border-radius: 50%;\
	border: 2px solid transparent;\
	cursor: pointer;\
	transition: border-color 0.15s, transform 0.1s;\
}\
.ttss-color-btn:hover {\
	border-color: rgba(255,255,255,0.4);\
	transform: scale(1.1);\
}\
.ttss-color-btn.active {\
	border-color: #4fc3f7;\
	box-shadow: 0 0 0 2px rgba(79,195,247,0.3);\
}\
.ttss-note-input {\
	width: 100%;\
	box-sizing: border-box;\
	border: 1px solid #555;\
	border-radius: 4px;\
	padding: 8px;\
	font-size: 12px;\
	resize: vertical;\
	margin-bottom: 10px;\
	font-family: inherit;\
	color: #e0e0e0;\
	background: #2a2a2a;\
	min-height: 44px;\
}\
.ttss-note-input::placeholder { color: #777; }\
.ttss-note-input:focus { outline: none; border-color: #4fc3f7; }\
.ttss-btn-row {\
	display: flex;\
	gap: 8px;\
	justify-content: flex-end;\
}\
.ttss-btn {\
	padding: 5px 14px;\
	border-radius: 4px;\
	border: 1px solid #555;\
	cursor: pointer;\
	font-size: 12px;\
	font-family: inherit;\
	color: #e0e0e0;\
	background: transparent;\
	transition: background 0.15s;\
}\
.ttss-btn:hover { background: rgba(255,255,255,0.1); }\
.ttss-btn-primary {\
	background: #4fc3f7;\
	color: #111;\
	border-color: #4fc3f7;\
}\
.ttss-btn-primary:hover { background: #29b6f6; }\
.ttss-btn-danger {\
	background: #e53935;\
	color: #fff;\
	border-color: #e53935;\
}\
.ttss-btn-danger:hover { background: #c62828; }\
';
	}

	function removePopup() {
		if (_outsideClickHandler) {
			document.removeEventListener('mousedown', _outsideClickHandler, true);
			_outsideClickHandler = null;
		}
		if (!_shadow) return;
		_shadow.querySelectorAll('.ttss-popup').forEach(function (el) { el.remove(); });
	}

	/**
	 * Popup für neues Highlight anzeigen.
	 */
	function showNewHighlight(rect, selectedText, onSave) {
		ensureHost();
		removePopup();

		var popup = createPopup(rect);

		// Vorschau
		var preview = document.createElement('div');
		preview.className = 'ttss-popup-preview';
		preview.textContent = selectedText.length > 100
			? selectedText.substring(0, 100) + '\u2026' : selectedText;
		popup.appendChild(preview);

		// Farben
		var colorRow = createColorRow('#fff3cd');
		popup.appendChild(colorRow);

		// Notiz
		var noteInput = createNoteInput('');
		popup.appendChild(noteInput);

		// Buttons
		var btnRow = document.createElement('div');
		btnRow.className = 'ttss-btn-row';

		var saveBtn = document.createElement('button');
		saveBtn.className = 'ttss-btn ttss-btn-primary';
		saveBtn.textContent = 'Speichern';
		saveBtn.addEventListener('click', function () {
			var activeColor = popup.querySelector('.ttss-color-btn.active');
			var color = activeColor ? activeColor.dataset.color : '#fff3cd';
			onSave(color, noteInput.value);
			removePopup();
		});

		var cancelBtn = document.createElement('button');
		cancelBtn.className = 'ttss-btn';
		cancelBtn.textContent = 'Abbrechen';
		cancelBtn.addEventListener('click', removePopup);

		btnRow.appendChild(saveBtn);
		btnRow.appendChild(cancelBtn);
		popup.appendChild(btnRow);

		_shadow.appendChild(popup);
		setupOutsideClickHandler(popup);
	}

	/**
	 * Popup für bestehendes Highlight bearbeiten.
	 */
	function showEditHighlight(rect, annId, currentText, currentNote, currentColor, onUpdate, onDelete) {
		ensureHost();
		removePopup();

		var popup = createPopup(rect);

		// Vorschau
		var preview = document.createElement('div');
		preview.className = 'ttss-popup-preview';
		preview.textContent = currentText.length > 100
			? currentText.substring(0, 100) + '\u2026' : currentText;
		popup.appendChild(preview);

		// Farben
		var colorRow = createColorRow(currentColor);
		popup.appendChild(colorRow);

		// Notiz
		var noteInput = createNoteInput(currentNote);
		popup.appendChild(noteInput);

		// Buttons
		var btnRow = document.createElement('div');
		btnRow.className = 'ttss-btn-row';

		var saveBtn = document.createElement('button');
		saveBtn.className = 'ttss-btn ttss-btn-primary';
		saveBtn.textContent = 'Aktualisieren';
		saveBtn.addEventListener('click', function () {
			var activeColor = popup.querySelector('.ttss-color-btn.active');
			var color = activeColor ? activeColor.dataset.color : currentColor;
			onUpdate(annId, color, noteInput.value);
			removePopup();
		});

		var deleteBtn = document.createElement('button');
		deleteBtn.className = 'ttss-btn ttss-btn-danger';
		deleteBtn.textContent = 'Löschen';
		deleteBtn.addEventListener('click', function () {
			onDelete(annId);
			removePopup();
		});

		var cancelBtn = document.createElement('button');
		cancelBtn.className = 'ttss-btn';
		cancelBtn.textContent = 'Abbrechen';
		cancelBtn.addEventListener('click', removePopup);

		btnRow.appendChild(saveBtn);
		btnRow.appendChild(deleteBtn);
		btnRow.appendChild(cancelBtn);
		popup.appendChild(btnRow);

		_shadow.appendChild(popup);
		setupOutsideClickHandler(popup);
	}

	// ─── Helfer ─────────────────────────────────────────────

	function createPopup(rect) {
		var popup = document.createElement('div');
		popup.className = 'ttss-popup';
		popup.style.left = Math.max(8, Math.min(rect.left, window.innerWidth - 380)) + 'px';
		// Popup über der Selektion anzeigen wenn unten kein Platz
		var top = rect.bottom + 8;
		if (top + 200 > window.innerHeight) {
			top = Math.max(8, rect.top - 208);
		}
		popup.style.top = top + 'px';
		return popup;
	}

	function createColorRow(activeColor) {
		var row = document.createElement('div');
		row.className = 'ttss-color-row';

		TTSS.highlighter.COLORS.forEach(function (color) {
			var btn = document.createElement('span');
			btn.className = 'ttss-color-btn';
			btn.style.backgroundColor = color;
			btn.dataset.color = color;
			if (color === activeColor) btn.classList.add('active');
			btn.addEventListener('click', function () {
				row.querySelectorAll('.ttss-color-btn').forEach(function (b) {
					b.classList.remove('active');
				});
				btn.classList.add('active');
			});
			row.appendChild(btn);
		});

		return row;
	}

	function createNoteInput(value) {
		var input = document.createElement('textarea');
		input.className = 'ttss-note-input';
		input.placeholder = 'Notiz hinzufügen (optional)\u2026';
		input.rows = 2;
		input.value = value || '';
		return input;
	}

	function setupOutsideClickHandler(popup) {
		setTimeout(function () {
			_outsideClickHandler = function (e) {
				// Prüfen ob Klick innerhalb des Shadow DOM ist
				var path = e.composedPath();
				var insidePopup = path.some(function (el) {
					return el === popup || el === _host;
				});
				if (!insidePopup) {
					removePopup();
				}
			};
			document.addEventListener('mousedown', _outsideClickHandler, true);
		}, 200);
	}

	return {
		showNewHighlight: showNewHighlight,
		showEditHighlight: showEditHighlight,
		removePopup: removePopup
	};
})();
