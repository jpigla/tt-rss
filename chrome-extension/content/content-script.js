/**
 * TT-SS Content Script — Hauptorchestrierer.
 * Koordiniert API-Client, Highlighter, Popup und Status-Bar.
 */
var TTSS = window.TTSS || {};

TTSS.controller = (function () {
	'use strict';

	var _pageUrl = location.href;
	var _pageStatus = null;
	var _annotations = [];

	/**
	 * Initialisierung bei Seitenladung.
	 */
	function init() {
		// Status der aktuellen Seite prüfen
		checkPageStatus();

		// Text-Selektion und Highlight-Klick Handler
		setupSelectionHandler();
		setupHighlightClickHandler();

		// Tab-Fokus: Re-sync
		document.addEventListener('visibilitychange', function () {
			if (document.visibilityState === 'visible') {
				checkPageStatus();
			}
		});

		// Messages vom Service Worker empfangen
		chrome.runtime.onMessage.addListener(function (msg, sender, sendResponse) {
			if (msg.type === 'save-page') {
				savePage().then(function (result) {
					sendResponse(result);
				});
				return true; // async response
			}
			if (msg.type === 'toggle-read-later') {
				toggleReadLater().then(function (result) {
					sendResponse(result);
				});
				return true;
			}
			if (msg.type === 'highlight-selection') {
				highlightCurrentSelection(msg.color || '#fff3cd');
				sendResponse({ status: 'ok' });
			}
			if (msg.type === 'get-page-content') {
				sendResponse({
					url: location.href,
					title: document.title,
					content: extractContent()
				});
			}
			if (msg.type === 'refresh-status') {
				checkPageStatus();
				sendResponse({ status: 'ok' });
			}
		});
	}

	/**
	 * Seitenstatus vom Backend abfragen.
	 */
	function checkPageStatus() {
		TTSS.api.getConfig().then(function (config) {
			if (!config.serverUrl || !config.apiKey) return;

			TTSS.api.getStatus(location.href).then(function (status) {
				_pageStatus = status;

				if (status.saved) {
					TTSS.statusBar.show(status, config.serverUrl);
					loadAnnotations();
				} else {
					TTSS.statusBar.hide();
				}

				// Badge im Service Worker aktualisieren
				chrome.runtime.sendMessage({
					type: 'update-badge',
					saved: status.saved
				});
			}).catch(function () {
				// Kein Backend erreichbar — still ignorieren
			});
		});
	}

	/**
	 * Annotationen laden und anwenden.
	 */
	function loadAnnotations() {
		TTSS.api.getAnnotations(location.href).then(function (annotations) {
			_annotations = annotations;
			TTSS.highlighter.applyHighlights(annotations);
			TTSS.statusBar.updateHighlightCount(annotations.length);
		});
	}

	/**
	 * Text-Selektion → Highlight-Popup anzeigen.
	 */
	function setupSelectionHandler() {
		document.addEventListener('mouseup', function (e) {
			// Nicht auf eigene UI reagieren
			if (e.target.closest && (
				e.target.closest('.ttss-status-bar-host') ||
				e.target.closest('.ttss-popup-host') ||
				e.target.closest('.ttss-highlight'))) return;

			var sel = window.getSelection();
			if (!sel || sel.isCollapsed || !sel.toString().trim()) return;

			// Nur wenn Seite gespeichert ist
			if (!_pageStatus || !_pageStatus.saved) return;

			var range = sel.getRangeAt(0);
			var rect = range.getBoundingClientRect();
			var selectedText = sel.toString().trim();

			if (!selectedText || selectedText.length < 2) return;

			TTSS.popup.showNewHighlight(rect, selectedText, function (color, note) {
				var selectorPath = TTSS.highlighter.captureContext(range);

				TTSS.api.saveAnnotation(
					location.href, selectedText, note, color,
					selectorPath, range.startOffset, range.endOffset
				).then(function (result) {
					if (result.status === 'ok') {
						var ann = {
							id: result.id,
							highlighted_text: selectedText,
							note: note,
							color: color,
							selector_path: selectorPath
						};
						_annotations.push(ann);
						TTSS.highlighter.applyOne(ann);
						TTSS.statusBar.updateHighlightCount(_annotations.length);
					}
				});

				window.getSelection().removeAllRanges();
			});
		});
	}

	/**
	 * Klick auf bestehendes Highlight → Bearbeiten-Popup.
	 */
	function setupHighlightClickHandler() {
		document.addEventListener('click', function (e) {
			var mark = e.target.closest('mark.ttss-highlight');
			if (!mark) return;

			e.preventDefault();
			e.stopPropagation();

			var annId = mark.dataset.annId;
			var rect = mark.getBoundingClientRect();

			TTSS.popup.showEditHighlight(
				rect,
				annId,
				mark.dataset.annText || mark.textContent,
				mark.dataset.annNote || '',
				mark.dataset.annColor || '#fff3cd',
				// onUpdate
				function (id, color, note) {
					TTSS.api.updateAnnotation(id, note, color).then(function (result) {
						if (result.status === 'ok') {
							mark.style.backgroundColor = color;
							mark.dataset.annNote = note;
							mark.dataset.annColor = color;
							mark.title = note || '';
							if (note) {
								mark.classList.add('ttss-has-note');
							} else {
								mark.classList.remove('ttss-has-note');
							}
						}
					});
				},
				// onDelete
				function (id) {
					TTSS.api.deleteAnnotation(id).then(function (result) {
						if (result.status === 'ok') {
							TTSS.highlighter.removeHighlight(id);
							_annotations = _annotations.filter(function (a) { return a.id != id; });
							TTSS.statusBar.updateHighlightCount(_annotations.length);
						}
					});
				}
			);
		}, true);
	}

	/**
	 * Aktuelle Selektion als Highlight speichern (für Context Menu).
	 */
	function highlightCurrentSelection(color) {
		var sel = window.getSelection();
		if (!sel || sel.isCollapsed || !sel.toString().trim()) return;

		if (!_pageStatus || !_pageStatus.saved) {
			// Erst Seite speichern, dann direkt annotieren (ohne Rekursion)
			savePage().then(function (result) {
				if (result && result.status === 'ok') {
					_pageStatus = { saved: true, ref_id: result.ref_id };
					// Annotation direkt speichern
					var r = sel.getRangeAt(0);
					var text = sel.toString().trim();
					if (!text) return;
					var sp = TTSS.highlighter.captureContext(r);
					TTSS.api.saveAnnotation(
						location.href, text, '', color,
						sp, r.startOffset, r.endOffset
					).then(function (annResult) {
						if (annResult.status === 'ok') {
							var a = { id: annResult.id, highlighted_text: text, note: '', color: color, selector_path: sp };
							_annotations.push(a);
							TTSS.highlighter.applyOne(a);
						}
					});
				}
			});
			return;
		}

		var range = sel.getRangeAt(0);
		var selectedText = sel.toString().trim();
		var selectorPath = TTSS.highlighter.captureContext(range);

		TTSS.api.saveAnnotation(
			location.href, selectedText, '', color,
			selectorPath, range.startOffset, range.endOffset
		).then(function (result) {
			if (result.status === 'ok') {
				var ann = {
					id: result.id,
					highlighted_text: selectedText,
					note: '',
					color: color,
					selector_path: selectorPath
				};
				_annotations.push(ann);
				TTSS.highlighter.applyOne(ann);
				TTSS.statusBar.updateHighlightCount(_annotations.length);
			}
		});

		sel.removeAllRanges();
	}

	/**
	 * Seite speichern.
	 */
	function savePage() {
		var content = extractContent();
		return TTSS.api.saveArticle(location.href, document.title, content).then(function (result) {
			if (result.status === 'ok') {
				checkPageStatus();
			}
			return result;
		});
	}

	/**
	 * Später lesen umschalten.
	 */
	function toggleReadLater() {
		if (!_pageStatus || !_pageStatus.saved) {
			return savePage().then(function () {
				return TTSS.api.toggleReadLater(location.href);
			});
		}
		return TTSS.api.toggleReadLater(location.href);
	}

	/**
	 * Hauptinhalt aus dem DOM extrahieren.
	 */
	function extractContent() {
		// Priorität: <article>, <main>, [role="main"], body
		var contentEl = document.querySelector('article')
			|| document.querySelector('main')
			|| document.querySelector('[role="main"]')
			|| document.body;

		// Klonen um Original nicht zu verändern
		var clone = contentEl.cloneNode(true);

		// Unerwünschte Elemente entfernen
		var removeSelectors = [
			'script', 'style', 'noscript', 'iframe',
			'nav', 'header', 'footer',
			'.ttss-status-bar-host', '.ttss-popup-host',
			'mark.ttss-highlight',
			'[role="navigation"]', '[role="banner"]', '[role="contentinfo"]',
			'.ad', '.ads', '.advertisement', '.social-share',
			'.cookie-banner', '.newsletter-signup'
		];

		removeSelectors.forEach(function (sel) {
			clone.querySelectorAll(sel).forEach(function (el) { el.remove(); });
		});

		// Event-Handler-Attribute entfernen (XSS-Prävention)
		clone.querySelectorAll('*').forEach(function (el) {
			var attrs = el.attributes;
			for (var i = attrs.length - 1; i >= 0; i--) {
				if (/^on/i.test(attrs[i].name)) {
					el.removeAttribute(attrs[i].name);
				}
			}
		});

		return clone.innerHTML;
	}

	return {
		init: init,
		checkPageStatus: checkPageStatus,
		loadAnnotations: loadAnnotations,
		savePage: savePage,
		extractContent: extractContent
	};
})();

// Starten
TTSS.controller.init();
