/**
 * TT-SS API Client — Kommunikation mit dem Backend.
 * Wird als Content Script geladen und global verfügbar gemacht.
 */
var TTSS = window.TTSS || {};

TTSS.api = (function () {
	'use strict';

	/**
	 * URL normalisieren: Fragment, utm_*, trailing Slash entfernen.
	 */
	function normalizeUrl(url) {
		try {
			var u = new URL(url);
			u.hash = '';
			// utm_* Parameter entfernen
			var params = new URLSearchParams(u.search);
			var toDelete = [];
			params.forEach(function (_, key) {
				if (/^utm_/i.test(key)) toDelete.push(key);
			});
			toDelete.forEach(function (key) { params.delete(key); });
			u.search = params.toString();
			// Trailing Slash entfernen (außer bei Root)
			var result = u.toString();
			if (u.pathname !== '/' && result.endsWith('/')) {
				result = result.slice(0, -1);
			}
			return result;
		} catch (e) {
			return url;
		}
	}

	/**
	 * Konfiguration aus chrome.storage.sync laden.
	 */
	function getConfig() {
		return new Promise(function (resolve) {
			chrome.storage.sync.get(['serverUrl', 'apiKey'], function (data) {
				resolve({
					serverUrl: (data.serverUrl || '').replace(/\/$/, ''),
					apiKey: data.apiKey || ''
				});
			});
		});
	}

	/**
	 * API-Aufruf an das Backend.
	 */
	function call(method, params) {
		return getConfig().then(function (config) {
			if (!config.serverUrl || !config.apiKey) {
				return Promise.reject(new Error('Server-URL oder API-Schlüssel nicht konfiguriert.'));
			}

			var url = config.serverUrl + '/public.php?op=pluginhandler&plugin=browser_extension&pmethod=' + method;
			var body = Object.assign({}, params, { api_key: config.apiKey });

			return fetch(url, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify(body)
			}).then(function (response) {
				if (!response.ok) {
					throw new Error('HTTP ' + response.status);
				}
				return response.json();
			});
		});
	}

	// ─── Öffentliche API ────────────────────────────────────────

	return {
		normalizeUrl: normalizeUrl,
		getConfig: getConfig,

		/** Health-Check */
		check: function () {
			return call('check', {});
		},

		/** Artikel speichern */
		saveArticle: function (url, title, content) {
			return call('save_article', { url: url, title: title, content: content });
		},

		/** Status einer URL abfragen */
		getStatus: function (url) {
			return call('get_status', { url: normalizeUrl(url) });
		},

		/** Annotationen für eine URL abrufen */
		getAnnotations: function (url) {
			return call('get_annotations_for_url', { url: normalizeUrl(url) });
		},

		/** Annotation speichern */
		saveAnnotation: function (url, highlightedText, note, color, selectorPath, startOffset, endOffset) {
			return call('save_annotation_for_url', {
				url: normalizeUrl(url),
				highlighted_text: highlightedText,
				note: note,
				color: color,
				selector_path: selectorPath,
				start_offset: startOffset,
				end_offset: endOffset
			});
		},

		/** Annotation aktualisieren */
		updateAnnotation: function (id, note, color) {
			return call('update_annotation_ext', { id: id, note: note, color: color });
		},

		/** Annotation löschen */
		deleteAnnotation: function (id) {
			return call('delete_annotation_ext', { id: id });
		},

		/** Alle Labels abrufen */
		getLabels: function () {
			return call('get_labels', {});
		},

		/** Labels für URL setzen */
		setLabels: function (url, labelIds) {
			return call('set_labels_for_url', { url: normalizeUrl(url), label_ids: labelIds });
		},

		/** Später-Lesen umschalten */
		toggleReadLater: function (url) {
			return call('toggle_read_later', { url: normalizeUrl(url) });
		},

		/** Seitennotiz abrufen */
		getPageNote: function (url) {
			return call('get_page_note', { url: normalizeUrl(url) });
		},

		/** Seitennotiz setzen */
		setPageNote: function (url, note) {
			return call('set_page_note', { url: normalizeUrl(url), note: note });
		}
	};
})();
