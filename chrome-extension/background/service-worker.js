/**
 * TT-SS Service Worker — Context Menus, Badge, Message-Routing.
 */

// ─── Context Menus registrieren ─────────────────────────────

chrome.runtime.onInstalled.addListener(function () {
	chrome.contextMenus.create({
		id: 'ttss-save-page',
		title: 'Seite in TT-SS speichern',
		contexts: ['page', 'frame']
	});

	chrome.contextMenus.create({
		id: 'ttss-read-later',
		title: '\uD83D\uDCCC Später lesen',
		contexts: ['page', 'frame']
	});

	chrome.contextMenus.create({
		id: 'ttss-separator',
		type: 'separator',
		contexts: ['selection']
	});

	chrome.contextMenus.create({
		id: 'ttss-highlight',
		title: 'Text markieren',
		contexts: ['selection']
	});

	// Farb-Submenü
	var colors = [
		{ id: 'yellow', color: '#fff3cd', label: 'Gelb' },
		{ id: 'green', color: '#d4edda', label: 'Grün' },
		{ id: 'blue', color: '#cce5ff', label: 'Blau' },
		{ id: 'red', color: '#f8d7da', label: 'Rot' },
		{ id: 'purple', color: '#e2d5f1', label: 'Lila' },
		{ id: 'orange', color: '#ffeeba', label: 'Orange' }
	];

	chrome.contextMenus.create({
		id: 'ttss-highlight-color',
		title: 'Text markieren als\u2026',
		contexts: ['selection']
	});

	colors.forEach(function (c) {
		chrome.contextMenus.create({
			id: 'ttss-highlight-' + c.id,
			parentId: 'ttss-highlight-color',
			title: c.label,
			contexts: ['selection']
		});
	});
});

// ─── Context Menu Klick-Handler ─────────────────────────────

var COLOR_MAP = {
	'ttss-highlight-yellow': '#fff3cd',
	'ttss-highlight-green': '#d4edda',
	'ttss-highlight-blue': '#cce5ff',
	'ttss-highlight-red': '#f8d7da',
	'ttss-highlight-purple': '#e2d5f1',
	'ttss-highlight-orange': '#ffeeba'
};

chrome.contextMenus.onClicked.addListener(function (info, tab) {
	if (!tab || !tab.id) return;

	if (info.menuItemId === 'ttss-save-page') {
		chrome.tabs.sendMessage(tab.id, { type: 'save-page' });
	}

	if (info.menuItemId === 'ttss-read-later') {
		chrome.tabs.sendMessage(tab.id, { type: 'toggle-read-later' });
	}

	if (info.menuItemId === 'ttss-highlight') {
		chrome.tabs.sendMessage(tab.id, { type: 'highlight-selection', color: '#fff3cd' });
	}

	if (COLOR_MAP[info.menuItemId]) {
		chrome.tabs.sendMessage(tab.id, { type: 'highlight-selection', color: COLOR_MAP[info.menuItemId] });
	}
});

// ─── Messages vom Content Script ────────────────────────────

chrome.runtime.onMessage.addListener(function (msg, sender) {
	if (msg.type === 'update-badge') {
		var tabId = sender.tab && sender.tab.id;
		if (!tabId) return;

		if (msg.saved) {
			chrome.action.setBadgeText({ text: '\u2713', tabId: tabId });
			chrome.action.setBadgeBackgroundColor({ color: '#4fc3f7', tabId: tabId });
		} else {
			chrome.action.setBadgeText({ text: '', tabId: tabId });
		}
	}
});
