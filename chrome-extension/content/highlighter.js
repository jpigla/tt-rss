/**
 * TT-SS Highlighter — TreeWalker-basiertes Text-Highlighting.
 * Portiert aus plugins.local/annotations/annotations.js
 */
var TTSS = window.TTSS || {};

TTSS.highlighter = (function () {
	'use strict';

	var COLORS = ['#fff3cd', '#d4edda', '#cce5ff', '#f8d7da', '#e2d5f1', '#ffeeba'];
	var _appliedIds = {};

	/**
	 * Alle Highlights auf der aktuellen Seite anwenden.
	 */
	function applyHighlights(annotations) {
		annotations.forEach(function (ann) {
			if (_appliedIds[ann.id]) return;
			applyOne(ann);
		});
	}

	/**
	 * Ein einzelnes Highlight anwenden.
	 */
	function applyOne(ann) {
		var textContent = ann.highlighted_text;
		if (!textContent) return false;

		if (document.querySelector('mark.ttss-highlight[data-ann-id="' + ann.id + '"]')) {
			_appliedIds[ann.id] = true;
			return true;
		}

		var context = null;
		if (ann.selector_path) {
			try { context = JSON.parse(ann.selector_path); } catch (e) { /* ignorieren */ }
		}

		var treeWalker = document.createTreeWalker(
			document.body, NodeFilter.SHOW_TEXT, null, false
		);

		var node;
		while ((node = treeWalker.nextNode())) {
			// Eigene Highlights und TT-SS UI überspringen
			if (node.parentElement && node.parentElement.closest &&
				(node.parentElement.closest('.ttss-highlight') ||
				 node.parentElement.closest('.ttss-status-bar-host') ||
				 node.parentElement.closest('.ttss-popup-host'))) continue;

			var searchFrom = 0;
			while (true) {
				var idx = node.textContent.indexOf(textContent, searchFrom);
				if (idx === -1) break;

				// Kontextprüfung
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
					mark.className = 'ttss-highlight';
					mark.style.backgroundColor = ann.color || '#fff3cd';
					mark.dataset.annId = ann.id;
					mark.dataset.annNote = ann.note || '';
					mark.dataset.annColor = ann.color || '#fff3cd';
					mark.dataset.annText = ann.highlighted_text;
					if (ann.note) {
						mark.title = ann.note;
						mark.classList.add('ttss-has-note');
					}

					var range = document.createRange();
					range.setStart(node, idx);
					range.setEnd(node, Math.min(idx + textContent.length, node.textContent.length));
					range.surroundContents(mark);

					_appliedIds[ann.id] = true;
					return true;
				} catch (e) {
					/* DOM-Grenzüberschreitung — nächsten Versuch starten */
				}
				break;
			}
		}

		return false;
	}

	/**
	 * Ein Highlight aus dem DOM entfernen.
	 */
	function removeHighlight(annId) {
		document.querySelectorAll('mark.ttss-highlight[data-ann-id="' + annId + '"]').forEach(function (el) {
			var parent = el.parentNode;
			while (el.firstChild) {
				parent.insertBefore(el.firstChild, el);
			}
			parent.removeChild(el);
			parent.normalize();
		});
		delete _appliedIds[annId];
	}

	/**
	 * Alle Highlights entfernen.
	 */
	function removeAll() {
		document.querySelectorAll('mark.ttss-highlight').forEach(function (el) {
			var parent = el.parentNode;
			while (el.firstChild) {
				parent.insertBefore(el.firstChild, el);
			}
			parent.removeChild(el);
			parent.normalize();
		});
		_appliedIds = {};
	}

	/**
	 * Kontext um die aktuelle Selektion erfassen.
	 */
	function captureContext(range) {
		var beforeText = '';
		try {
			var startNode = range.startContainer;
			beforeText = startNode.textContent.substring(
				Math.max(0, range.startOffset - 20), range.startOffset);
		} catch (e) { /* ignorieren */ }

		return JSON.stringify({ before: beforeText });
	}

	/**
	 * Anzahl der angewendeten Highlights.
	 */
	function getCount() {
		return Object.keys(_appliedIds).length;
	}

	return {
		COLORS: COLORS,
		applyHighlights: applyHighlights,
		applyOne: applyOne,
		removeHighlight: removeHighlight,
		removeAll: removeAll,
		captureContext: captureContext,
		getCount: getCount
	};
})();
