/**
 * Parser Rules -- Frontend-Logik für den "Regel lernen"-Button im Reader Mode.
 *
 * Läuft als einfaches IIFE im Reader-Kontext (reader.php, kein Dojo/AMD).
 * Wird über get_reader_js() eingebettet und nach reader_page.js ausgeführt.
 *
 * Integration: MutationObserver beobachtet document.body auf direkt eingefügte
 * ann-context-toolbar-Elemente (von reader_page.js direkt an body angehängt).
 *
 * Sicherheitshinweis: Alle Benutzer-Eingaben werden über textContent-Zuweisung
 * in DOM eingefügt (kein innerHTML mit Nutzerdaten).
 */
(function () {
	'use strict';

	// Nur im Reader-Kontext aktiv (window.__readerMeta gesetzt von reader.php)
	if (!window.__readerMeta) return;

	var meta = window.__readerMeta;
	var csrfToken = window.__csrfToken || '';

	var _observer = null;
	var _escHandler = null;

	// ── MutationObserver: Toolbar-Injection ──────────

	function observeToolbar() {
		_observer = new MutationObserver(function (mutations) {
			mutations.forEach(function (m) {
				m.addedNodes.forEach(function (node) {
					if (node.nodeType === 1 && node.classList &&
						node.classList.contains('ann-context-toolbar')) {
						injectLearnButton(node);
					}
				});
			});
		});

		// ann-context-toolbar wird direkt an document.body angehängt
		_observer.observe(document.body, {childList: true, subtree: false});
	}

	function injectLearnButton(toolbar) {
		if (toolbar.querySelector('.pr-learn-btn')) return;

		var btn = document.createElement('button');
		btn.className = 'ann-toolbar-btn pr-learn-btn';
		btn.title = 'Extraktionsregel lernen';
		btn.appendChild(createWandIcon());

		btn.addEventListener('click', function (e) {
			e.stopPropagation();
			onLearnClick(toolbar);
		});

		var moreBtn = toolbar.querySelector('.ann-toolbar-btn-more');
		if (moreBtn) {
			toolbar.insertBefore(btn, moreBtn);
		} else {
			toolbar.appendChild(btn);
		}
	}

	function createWandIcon() {
		var ns = 'http://www.w3.org/2000/svg';
		var svg = document.createElementNS(ns, 'svg');
		svg.setAttribute('viewBox', '0 0 24 24');
		svg.setAttribute('width', '18');
		svg.setAttribute('height', '18');
		svg.setAttribute('fill', 'currentColor');
		var path = document.createElementNS(ns, 'path');
		path.setAttribute('d', 'M7.5 5.6L10 7 8.6 4.5 10 2 7.5 3.4 5 2l1.4 2.5L5 7zm12 ' +
			'9.8L17 14l1.4 2.5L17 19l2.5-1.4L22 19l-1.4-2.5L22 14zM22 2l-2.5 1.4L17 2l1.4 ' +
			'2.5L17 7l2.5-1.4L22 7l-1.4-2.5zm-7.63 5.29a.9959.9959 0 0 0-1.41 0L1.29 18.96c-' +
			'.39.39-.39 1.02 0 1.41l2.34 2.34c.39.39 1.02.39 1.41 0L16.7 11.05c.39-.39.39-' +
			'1.02 0-1.41l-2.33-2.35zM5.04 21.29l-2.33-2.33 6.71-6.71 2.33 2.33-6.71 6.71z');
		svg.appendChild(path);
		return svg;
	}

	// ── Regel-Erstellung ─────────────────────────────

	function onLearnClick(toolbar) {
		var sel = window.getSelection();
		if (!sel || sel.isCollapsed || !sel.toString().trim()) {
			showNotify('Kein Text ausgewählt', 'error');
			return;
		}

		var selectedText = sel.toString().trim();
		if (selectedText.length < 50) {
			showNotify('Bitte mindestens 50 Zeichen auswählen', 'error');
			return;
		}

		if (!meta.link || !meta.domain) {
			showNotify('Reader-Metadaten nicht verfügbar', 'error');
			return;
		}

		var range = sel.getRangeAt(0);
		var domContext = captureDomContext(range);

		// Toolbar schließen (lokale Funktion in reader_page.js ist nicht extern
		// erreichbar — Toolbar über DOM-Manipulation entfernen)
		document.querySelectorAll('.ann-context-toolbar').forEach(function (t) {
			t.remove();
		});

		showConfirmPanel(selectedText, domContext);
	}

	function captureDomContext(range) {
		var ancestor = range.commonAncestorContainer;
		if (ancestor.nodeType === 3) ancestor = ancestor.parentNode;

		var chain = [];
		var node = ancestor;
		var wrapper = document.querySelector('.annotations-wrapper');

		while (node && node !== wrapper && node !== document.body) {
			var part = node.tagName ? node.tagName.toLowerCase() : '';
			if (part) {
				if (node.className && typeof node.className === 'string') {
					var cls = node.className.trim().split(/\s+/)[0];
					if (cls) part += '.' + cls;
				}
				if (node.id) part += '#' + node.id;
				chain.unshift(part);
			}
			node = node.parentNode;
		}

		var surroundingHtml = '';
		try {
			var container = ancestor;
			while (container && container !== wrapper &&
				!['ARTICLE', 'MAIN', 'SECTION', 'DIV'].includes(container.tagName)) {
				container = container.parentNode;
			}
			if (container && container !== document.body) {
				surroundingHtml = container.outerHTML.substring(0, 3000);
			}
		} catch (e) { /* ignorieren */ }

		return {
			parent_chain: chain.join(' > '),
			surrounding_html: surroundingHtml
		};
	}

	// ── Bestätigungs-Panel ───────────────────────────

	function showConfirmPanel(selectedText, domContext) {
		removePanel();

		var panel = document.createElement('div');
		panel.className = 'pr-confirm-panel';

		// Header
		var header = document.createElement('div');
		header.className = 'pr-panel-header';
		var headerIcon = document.createElement('i');
		headerIcon.className = 'material-icons';
		headerIcon.textContent = 'auto_fix_high';
		var headerText = document.createElement('span');
		headerText.textContent = 'Extraktionsregel erstellen';
		header.appendChild(headerIcon);
		header.appendChild(headerText);

		// Domain
		var domainDiv = document.createElement('div');
		domainDiv.className = 'pr-panel-domain';
		domainDiv.textContent = 'Domain: ';
		var domainStrong = document.createElement('strong');
		domainStrong.textContent = meta.domain;
		domainDiv.appendChild(domainStrong);

		// Preview
		var preview = document.createElement('div');
		preview.className = 'pr-panel-preview';
		preview.textContent = selectedText.substring(0, 120) +
			(selectedText.length > 120 ? '\u2026' : '');

		// Actions
		var actions = document.createElement('div');
		actions.className = 'pr-panel-actions';

		var includeBtn = document.createElement('button');
		includeBtn.className = 'pr-btn pr-btn-include';
		var inclIcon = document.createElement('i');
		inclIcon.className = 'material-icons';
		inclIcon.textContent = 'check_circle';
		includeBtn.appendChild(inclIcon);
		includeBtn.appendChild(document.createTextNode(' Diesen Inhalt extrahieren'));

		var excludeBtn = document.createElement('button');
		excludeBtn.className = 'pr-btn pr-btn-exclude';
		var exclIcon = document.createElement('i');
		exclIcon.className = 'material-icons';
		exclIcon.textContent = 'block';
		excludeBtn.appendChild(exclIcon);
		excludeBtn.appendChild(document.createTextNode(' Diesen Bereich entfernen'));

		var cancelBtn = document.createElement('button');
		cancelBtn.className = 'pr-btn pr-btn-cancel';
		cancelBtn.textContent = 'Abbrechen';

		actions.appendChild(includeBtn);
		actions.appendChild(excludeBtn);
		actions.appendChild(cancelBtn);

		panel.appendChild(header);
		panel.appendChild(domainDiv);
		panel.appendChild(preview);
		panel.appendChild(actions);

		document.body.appendChild(panel);

		includeBtn.addEventListener('click', function () {
			submitRule(panel, selectedText, domContext, 'include');
		});
		excludeBtn.addEventListener('click', function () {
			submitRule(panel, selectedText, domContext, 'exclude');
		});
		cancelBtn.addEventListener('click', function () {
			removePanel();
		});

		// Escape-Handler: stopImmediatePropagation verhindert history.back() in
		// reader_page.js's Keyboard-Shortcut-Handler
		_escHandler = function (e) {
			if (e.key === 'Escape') {
				e.stopImmediatePropagation();
				removePanel();
			}
		};
		document.addEventListener('keydown', _escHandler, true);
	}

	function submitRule(panel, selectedText, domContext, ruleType) {
		var actions = panel.querySelector('.pr-panel-actions');
		while (actions.firstChild) actions.removeChild(actions.firstChild);

		var spinner = document.createElement('div');
		spinner.className = 'pr-spinner';
		var spinIcon = document.createElement('i');
		spinIcon.className = 'material-icons pr-spin';
		spinIcon.textContent = 'autorenew';
		var spinText = document.createElement('span');
		spinText.textContent = 'KI analysiert Seitenstruktur\u2026';
		spinner.appendChild(spinIcon);
		spinner.appendChild(spinText);
		actions.appendChild(spinner);

		var params = new URLSearchParams();
		params.append('op', 'pluginhandler');
		params.append('plugin', 'parser_rules');
		params.append('method', 'learn_rule');
		params.append('csrf_token', csrfToken);
		params.append('article_url', meta.link);
		params.append('domain', meta.domain);
		params.append('selected_text', selectedText);
		params.append('parent_chain', domContext.parent_chain);
		params.append('surrounding_html', domContext.surrounding_html);
		params.append('rule_type', ruleType);

		fetch('../../backend.php', {
			method: 'POST',
			body: params,
			credentials: 'same-origin'
		})
		.then(function (r) { return r.json(); })
		.then(function (data) {
			if (data.error) {
				showResult(panel, false, data.error);
			} else {
				var msg = (data.ai_used ? 'KI-optimiert' : 'Fallback') +
					' \u2014 Konfidenz: ' + Math.round(data.confidence * 100) + '%' +
					' \u2014 ' + data.css_selector;
				showResult(panel, true, msg, data.reasoning);
			}
		})
		.catch(function (err) {
			showResult(panel, false, 'Netzwerkfehler: ' + err.message);
		});
	}

	function showResult(panel, success, message, reasoning) {
		var actions = panel.querySelector('.pr-panel-actions');
		if (!actions) {
			removePanel();
			return;
		}
		while (actions.firstChild) actions.removeChild(actions.firstChild);

		var result = document.createElement('div');
		result.className = success ? 'pr-result-success' : 'pr-result-error';
		var icon = document.createElement('i');
		icon.className = 'material-icons';
		icon.textContent = success ? 'check_circle' : 'error';
		var msgSpan = document.createElement('span');
		msgSpan.textContent = message;
		result.appendChild(icon);
		result.appendChild(msgSpan);
		actions.appendChild(result);

		if (reasoning) {
			var reasonDiv = document.createElement('div');
			reasonDiv.className = 'pr-result-reasoning';
			reasonDiv.textContent = reasoning;
			actions.appendChild(reasonDiv);
		}

		var closeBtn = document.createElement('button');
		closeBtn.className = 'pr-btn pr-btn-cancel';
		closeBtn.style.marginTop = '8px';
		closeBtn.textContent = 'Schließen';
		actions.appendChild(closeBtn);

		closeBtn.addEventListener('click', function () {
			removePanel();
		});

		if (success) {
			showNotify('Extraktionsregel gespeichert');
		}
	}

	function removePanel() {
		var panel = document.querySelector('.pr-confirm-panel');
		if (panel) panel.remove();
		if (_escHandler) {
			document.removeEventListener('keydown', _escHandler, true);
			_escHandler = null;
		}
	}

	// ── Hilfsfunktionen ──────────────────────────────

	function showNotify(msg, type) {
		var n = document.createElement('div');
		n.className = 'pr-notify' + (type === 'error' ? ' pr-notify-error' : '');
		n.textContent = msg;
		document.body.appendChild(n);
		setTimeout(function () { n.remove(); }, 3000);
	}

	// ── Init ─────────────────────────────────────────

	observeToolbar();

})();
