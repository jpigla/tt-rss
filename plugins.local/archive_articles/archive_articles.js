/* ═══════════════════════════════════════════════════════════════
   Archive Articles — Archiv-Buttons, Kontextmenü, Purge-Einstellungen
   ═══════════════════════════════════════════════════════════════ */

'use strict';

const ArchiveArticles = {

	PLUGIN_URL: 'backend.php',

	_callApi: function (method, ids, callback) {
		const query = {
			op: 'PluginHandler',
			plugin: 'archive_articles',
			method: method,
			ids: ids.toString()
		};

		xhr.json(ArchiveArticles.PLUGIN_URL, query, function () {
			if (callback) callback();
			Feeds.reloadCurrent();
		});
	},

	archiveSelection: function () {
		const rows = Headlines.getSelected();
		if (rows.length === 0) {
			alert(__('No articles selected.'));
			return;
		}
		ArchiveArticles._callApi('archiveSelection', rows);
	},

	unarchiveSelection: function () {
		const rows = Headlines.getSelected();
		if (rows.length === 0) {
			alert(__('No articles selected.'));
			return;
		}
		ArchiveArticles._callApi('unarchiveSelection', rows);
	},

	/**
	 * Wird vom Artikel-Button aufgerufen.
	 * Archiviert oder dearchiviert einen einzelnen Artikel.
	 */
	toggleFromButton: function (id, isArchived) {
		const method = isArchived ? 'unarchiveSelection' : 'archiveSelection';
		ArchiveArticles._callApi(method, [id], function () {
			// Button-Zustand aktualisieren
			const btn = document.querySelector('.aa-btn[data-article-id="' + id + '"]');
			if (!btn) return;

			const nowArchived = !isArchived;
			btn.textContent  = nowArchived ? 'unarchive' : 'archive';
			btn.title        = nowArchived ? __('Dearchivieren') : __('Archivieren (Purge-Schutz)');
			btn.dataset.archived = nowArchived ? '1' : '0';
			btn.classList.toggle('aa-btn--archived', nowArchived);
			btn.setAttribute('onclick',
				'ArchiveArticles.toggleFromButton(' + id + ', ' + (nowArchived ? 'true' : 'false') + ')');
		});
	},

	/**
	 * Feed-Purge auf "Nie" setzen (-1).
	 */
	setNeverPurge: function (feedId, btn) {
		xhr.json(ArchiveArticles.PLUGIN_URL, {
			op: 'PluginHandler',
			plugin: 'archive_articles',
			method: 'setFeedPurge',
			feed_id: feedId,
			purge_interval: -1
		}, function (reply) {
			Notify.info(reply || __('Gespeichert.'));
			// Zeile aktualisieren ohne Reload
			if (btn) {
				const row = btn.closest('tr');
				if (row) {
					row.classList.add('aa-never-purge');
					const cell = row.querySelector('td:nth-child(2)');
					if (cell) cell.textContent = __('Nie');
					btn.textContent = __('Standard');
					btn.setAttribute('onclick',
						'ArchiveArticles.resetPurge(' + feedId + ', this); return false');
				}
			}
		});
	},

	/**
	 * Feed-Purge auf Standard zurücksetzen (0 = Benutzereinstellung).
	 */
	resetPurge: function (feedId, btn) {
		xhr.json(ArchiveArticles.PLUGIN_URL, {
			op: 'PluginHandler',
			plugin: 'archive_articles',
			method: 'setFeedPurge',
			feed_id: feedId,
			purge_interval: 0
		}, function (reply) {
			Notify.info(reply || __('Gespeichert.'));
			if (btn) {
				const row = btn.closest('tr');
				if (row) {
					row.classList.remove('aa-never-purge');
					const cell = row.querySelector('td:nth-child(2)');
					if (cell) cell.textContent = __('Standard');
					btn.textContent = __('Nie purgen');
					btn.setAttribute('onclick',
						'ArchiveArticles.setNeverPurge(' + feedId + ', this); return false');
				}
			}
		});
	},

	/**
	 * Fügt Archiv-Optionen in das Bulk-Actions-Dropdown ein.
	 */
	enhanceBulkActions: function () {
		const selects = document.querySelectorAll('select[onchange*="headlineActing"]');
		selects.forEach(function (sel) {
			if (sel.dataset.archiveDone) return;
			sel.dataset.archiveDone = '1';

			const archiveOpt = document.createElement('option');
			archiveOpt.value = 'archive_archiveSelection';
			archiveOpt.textContent = __('Archive');

			const unarchiveOpt = document.createElement('option');
			unarchiveOpt.value = 'archive_unarchiveSelection';
			unarchiveOpt.textContent = __('Unarchive');

			sel.appendChild(archiveOpt);
			sel.appendChild(unarchiveOpt);
		});
	},

	/**
	 * Fügt Archiv-Eintrag ins Kontextmenü ein (dijit.Menu).
	 */
	enhanceContextMenu: function () {
		var menu;
		try {
			menu = dijit.byId('headlinesMenu');
		} catch (e) {
			return;
		}
		if (!menu || menu._archiveDone) return;
		menu._archiveDone = true;

		menu.addChild(new dijit.MenuSeparator());

		var archiveItem = new dijit.MenuItem({
			label: __('Archive'),
			onClick: function () {
				var isArchive = Feeds.getActive() === 0 && !Feeds.activeIsCat();
				var method = isArchive ? 'unarchiveSelection' : 'archiveSelection';
				var id = parseInt(this.getParent().currentTarget.getAttribute('data-article-id'));
				var ids = Headlines.getSelected();
				ids = ids.includes(id) ? ids : [id];
				ArchiveArticles._callApi(method, ids);
			}
		});

		menu.addChild(archiveItem);

		dojo.connect(menu, '_openMyself', function () {
			var isArchive = Feeds.getActive() === 0 && !Feeds.activeIsCat();
			archiveItem.set('label', isArchive ? __('Unarchive') : __('Archive'));
		});
	},

	handleBulkAction: function (value) {
		if (value === 'archive_archiveSelection') {
			ArchiveArticles.archiveSelection();
			return true;
		}
		if (value === 'archive_unarchiveSelection') {
			ArchiveArticles.unarchiveSelection();
			return true;
		}
		return false;
	},

	init: function () {
		setTimeout(function () {
			ArchiveArticles.enhanceContextMenu();
		}, 1000);

		document.addEventListener('change', function (e) {
			var target = e.target;
			if (target && target.tagName === 'SELECT' && target.onchange) {
				var val = target.value;
				if (ArchiveArticles.handleBulkAction(val)) {
					target.selectedIndex = 0;
					e.stopPropagation();
				}
			}
		}, true);

		var observer = new MutationObserver(function (mutations) {
			for (var m of mutations) {
				if (m.addedNodes.length > 0) {
					ArchiveArticles.enhanceContextMenu();
					break;
				}
			}
		});

		var body = document.body;
		if (body) {
			observer.observe(body, { childList: true, subtree: true });
		}
	}
};

if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', ArchiveArticles.init);
} else {
	ArchiveArticles.init();
}
