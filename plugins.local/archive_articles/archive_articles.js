/* ═══════════════════════════════════════════════════════════════
   Archive Articles — Archiv-Buttons, Kontextmenü, API
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
			alert(__("No articles selected."));
			return;
		}
		ArchiveArticles._callApi('archiveSelection', rows);
	},

	unarchiveSelection: function () {
		const rows = Headlines.getSelected();
		if (rows.length === 0) {
			alert(__("No articles selected."));
			return;
		}
		ArchiveArticles._callApi('unarchiveSelection', rows);
	},

	/**
	 * Fuegt Archiv-Optionen in das Bulk-Actions-Dropdown ein.
	 */
	enhanceBulkActions: function () {
		const selects = document.querySelectorAll('select[onchange*="headlineActing"]');
		selects.forEach(function (sel) {
			if (sel.dataset.archiveDone) return;
			sel.dataset.archiveDone = '1';

			// Archiv-Option hinzufuegen
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
	 * Fuegt Archiv-Eintrag ins Kontextmenü ein (dijit.Menu).
	 * Wird ueber MutationObserver nach dem Rendern aufgerufen.
	 */
	enhanceContextMenu: function () {
		// Das Headlines-Kontextmenu heisst 'headlinesMenu'
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
			label: __("Archive"),
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
			archiveItem.set('label', isArchive ? __("Unarchive") : __("Archive"));
		});
	},

	/**
	 * Überwacht das Bulk-Actions-Dropdown fuer die eigenen Aktionen.
	 */
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
		// Kontextmenu erweitern (muss warten bis dijit geladen hat)
		setTimeout(function () {
			ArchiveArticles.enhanceContextMenu();
		}, 1000);

		// Überwache Bulk-Actions Dropdown via Event-Delegation
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

		// Beobachte DOM-Aenderungen fuer dynamische Elemente
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
