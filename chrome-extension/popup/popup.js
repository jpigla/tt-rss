/**
 * TT-SS Popup — Seite speichern, Labels, Später lesen.
 */
(function () {
	'use strict';

	var _config = {};
	var _pageStatus = null;
	var _allLabels = [];
	var _tabId = null;

	// ─── Init ───────────────────────────────────────────────────

	document.addEventListener('DOMContentLoaded', function () {
		document.getElementById('open-options').addEventListener('click', function () {
			chrome.runtime.openOptionsPage();
		});

		document.getElementById('save-btn').addEventListener('click', saveCurrentPage);
		document.getElementById('read-later-btn').addEventListener('click', toggleReadLater);

		loadConfig();
	});

	function loadConfig() {
		chrome.storage.sync.get(['serverUrl', 'apiKey'], function (data) {
			_config.serverUrl = (data.serverUrl || '').replace(/\/$/, '');
			_config.apiKey = data.apiKey || '';

			if (!_config.serverUrl || !_config.apiKey) {
				document.getElementById('loading').style.display = 'none';
				document.getElementById('not-configured').style.display = 'block';
				return;
			}

			// Aktuellen Tab holen
			chrome.tabs.query({ active: true, currentWindow: true }, function (tabs) {
				if (!tabs[0]) return;
				_tabId = tabs[0].id;
				checkStatus(tabs[0].url);
			});
		});
	}

	// ─── API-Aufrufe ────────────────────────────────────────────

	function apiCall(method, params) {
		var url = _config.serverUrl + '/public.php?op=pluginhandler&plugin=browser_extension&pmethod=' + method;
		var body = Object.assign({}, params, { api_key: _config.apiKey });

		return fetch(url, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify(body)
		}).then(function (r) {
			if (!r.ok) throw new Error('HTTP ' + r.status);
			return r.json();
		});
	}

	function normalizeUrl(url) {
		try {
			var u = new URL(url);
			u.hash = '';
			var params = new URLSearchParams(u.search);
			var toDelete = [];
			params.forEach(function (_, key) {
				if (/^utm_/i.test(key)) toDelete.push(key);
			});
			toDelete.forEach(function (key) { params.delete(key); });
			u.search = params.toString();
			var result = u.toString();
			if (u.pathname !== '/' && result.endsWith('/')) {
				result = result.slice(0, -1);
			}
			return result;
		} catch (e) { return url; }
	}

	// ─── Status prüfen ─────────────────────────────────────────

	function checkStatus(url) {
		apiCall('get_status', { url: normalizeUrl(url) }).then(function (status) {
			_pageStatus = status;

			document.getElementById('loading').style.display = 'none';
			document.getElementById('content').style.display = 'block';

			if (status.saved) {
				document.getElementById('status-saved').style.display = 'inline-flex';
				document.getElementById('status-unsaved').style.display = 'none';
				document.getElementById('save-btn').textContent = 'Erneut speichern';

				// Labels laden
				loadLabels(status);

				// Info
				document.getElementById('info-section').style.display = 'block';
				document.getElementById('annotation-count').textContent = status.annotation_count || 0;

				// Später lesen
				document.getElementById('read-later-section').style.display = 'block';
				updateReadLaterBtn(status.read_later);
			} else {
				document.getElementById('status-saved').style.display = 'none';
				document.getElementById('status-unsaved').style.display = 'inline-flex';
				document.getElementById('save-btn').textContent = 'Seite speichern';
			}
		}).catch(function () {
			document.getElementById('loading').style.display = 'none';
			document.getElementById('not-configured').style.display = 'block';
			document.getElementById('not-configured').querySelector('p').textContent =
				'Verbindung zum Server fehlgeschlagen.';
		});
	}

	// ─── Labels ─────────────────────────────────────────────────

	function loadLabels(status) {
		apiCall('get_labels', {}).then(function (labels) {
			_allLabels = labels;

			var section = document.getElementById('labels-section');
			section.style.display = 'block';

			var list = document.getElementById('labels-list');
			// Liste leeren
			while (list.firstChild) {
				list.removeChild(list.firstChild);
			}

			var currentIds = (status.labels || []).map(function (l) { return l.id; });

			labels.forEach(function (label) {
				var item = document.createElement('label');
				item.className = 'label-item';

				var checkbox = document.createElement('input');
				checkbox.type = 'checkbox';
				checkbox.className = 'label-checkbox';
				checkbox.checked = currentIds.indexOf(label.id) !== -1;
				checkbox.addEventListener('change', function () {
					onLabelChange();
				});
				checkbox.dataset.labelId = label.id;

				var dot = document.createElement('span');
				dot.className = 'label-dot';
				dot.style.backgroundColor = label.bg_color || '#555';

				var name = document.createElement('span');
				name.className = 'label-name';
				name.textContent = label.caption;

				item.appendChild(checkbox);
				item.appendChild(dot);
				item.appendChild(name);
				list.appendChild(item);
			});
		});
	}

	function onLabelChange() {
		var checkboxes = document.querySelectorAll('.label-checkbox');
		var selectedIds = [];
		checkboxes.forEach(function (cb) {
			if (cb.checked) selectedIds.push(parseInt(cb.dataset.labelId));
		});

		chrome.tabs.query({ active: true, currentWindow: true }, function (tabs) {
			if (!tabs[0]) return;
			apiCall('set_labels_for_url', {
				url: normalizeUrl(tabs[0].url),
				label_ids: selectedIds
			}).then(function () {
				// Content Script benachrichtigen
				chrome.tabs.sendMessage(tabs[0].id, { type: 'refresh-status' });
			});
		});
	}

	// ─── Seite speichern ────────────────────────────────────────

	function saveCurrentPage() {
		var btn = document.getElementById('save-btn');
		btn.disabled = true;
		btn.textContent = 'Wird gespeichert\u2026';

		chrome.tabs.sendMessage(_tabId, { type: 'get-page-content' }, function (response) {
			if (!response) {
				btn.disabled = false;
				btn.textContent = 'Fehler \u2014 erneut versuchen';
				return;
			}

			apiCall('save_article', {
				url: response.url,
				title: response.title,
				content: response.content
			}).then(function (result) {
				if (result.status === 'ok') {
					btn.textContent = '\u2713 Gespeichert!';

					// Status neu laden
					setTimeout(function () {
						checkStatus(response.url);
						chrome.tabs.sendMessage(_tabId, { type: 'refresh-status' });
					}, 500);
				} else {
					btn.disabled = false;
					btn.textContent = 'Fehler: ' + (result.message || 'Unbekannt');
				}
			}).catch(function () {
				btn.disabled = false;
				btn.textContent = 'Verbindungsfehler';
			});
		});
	}

	// ─── Später lesen ───────────────────────────────────────────

	function toggleReadLater() {
		chrome.tabs.query({ active: true, currentWindow: true }, function (tabs) {
			if (!tabs[0]) return;

			apiCall('toggle_read_later', { url: normalizeUrl(tabs[0].url) }).then(function (result) {
				if (result.status === 'ok') {
					updateReadLaterBtn(result.read_later);
					chrome.tabs.sendMessage(tabs[0].id, { type: 'refresh-status' });
				}
			});
		});
	}

	function updateReadLaterBtn(isActive) {
		var btn = document.getElementById('read-later-btn');
		if (isActive) {
			btn.classList.add('active');
			btn.textContent = '\uD83D\uDCCC Aus Leseliste entfernen';
		} else {
			btn.classList.remove('active');
			btn.textContent = '\uD83D\uDCCC Später lesen';
		}
	}
})();
