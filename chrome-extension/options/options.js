/**
 * TT-SS Options — Server-URL und API-Schlüssel konfigurieren.
 */
(function () {
	'use strict';

	var serverUrlInput = null;
	var apiKeyInput = null;
	var statusEl = null;

	document.addEventListener('DOMContentLoaded', function () {
		serverUrlInput = document.getElementById('server-url');
		apiKeyInput = document.getElementById('api-key');
		statusEl = document.getElementById('status');

		document.getElementById('save-btn').addEventListener('click', save);
		document.getElementById('test-btn').addEventListener('click', testConnection);

		// Gespeicherte Werte laden
		chrome.storage.sync.get(['serverUrl', 'apiKey'], function (data) {
			serverUrlInput.value = data.serverUrl || '';
			apiKeyInput.value = data.apiKey || '';
		});
	});

	function save() {
		var serverUrl = serverUrlInput.value.trim().replace(/\/$/, '');
		var apiKey = apiKeyInput.value.trim();

		if (!serverUrl) {
			showStatus('Bitte Server-URL eingeben.', 'error');
			return;
		}

		if (!serverUrl.startsWith('https://') && !serverUrl.startsWith('http://localhost') && !serverUrl.startsWith('http://127.0.0.1')) {
			showStatus('Warnung: HTTPS wird dringend empfohlen, da der API-Schl\u00FCssel \u00FCbertragen wird.', 'error');
			return;
		}

		if (!apiKey) {
			showStatus('Bitte API-Schlüssel eingeben.', 'error');
			return;
		}

		chrome.storage.sync.set({
			serverUrl: serverUrl,
			apiKey: apiKey
		}, function () {
			showStatus('Einstellungen gespeichert!', 'success');
		});
	}

	function testConnection() {
		var serverUrl = serverUrlInput.value.trim().replace(/\/$/, '');

		if (!serverUrl) {
			showStatus('Bitte zuerst eine Server-URL eingeben.', 'error');
			return;
		}

		showStatus('Teste Verbindung\u2026', 'success');

		var url = serverUrl + '/public.php?op=pluginhandler&plugin=browser_extension&pmethod=check';

		fetch(url, { method: 'GET' })
			.then(function (response) {
				if (!response.ok) throw new Error('HTTP ' + response.status);
				return response.json();
			})
			.then(function (data) {
				if (data.status === 'ok') {
					showStatus('Verbindung erfolgreich! Server Version: ' + (data.version || 'unbekannt'), 'success');
				} else {
					showStatus('Server antwortet, aber Status ist nicht ok.', 'error');
				}
			})
			.catch(function (err) {
				showStatus('Verbindung fehlgeschlagen: ' + err.message, 'error');
			});
	}

	function showStatus(message, type) {
		statusEl.textContent = message;
		statusEl.className = 'status ' + type;
		statusEl.style.display = 'block';
	}
})();
