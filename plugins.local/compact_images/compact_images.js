/* ═══════════════════════════════════════════════════════════════
   Compact Images — URL-Anzeige + Enclosure-Kompaktierung
   ═══════════════════════════════════════════════════════════════
   1. Fügt die Original-URL des Artikels unter dem Titel ein
   2. Kompaktiert das erste große Enclosure-Bild als Thumbnail

   Unterstützt:
   - CDM-Ansicht (Combined Mode): URL neben dem Autor
   - Drei-Panel-Ansicht: URL als eigene Zeile unter dem Titel
   - Enclosure-Bilder in beiden Ansichten
   ═══════════════════════════════════════════════════════════════ */

'use strict';

const CompactImages = {

	/**
	 * Kürzt eine URL auf Domain + Pfad (ohne Protokoll, Query, Fragment).
	 * Lange Pfade werden mit Ellipsis abgekürzt.
	 */
	formatUrl: function (url) {
		try {
			const parsed = new URL(url);
			let display = parsed.hostname.replace(/^www\./, '');
			const path = parsed.pathname;

			if (path && path !== '/') {
				// Pfad kürzen wenn zu lang
				if (path.length > 50) {
					display += path.substring(0, 47) + '...';
				} else {
					display += path;
				}
			}

			return display;
		} catch (e) {
			return url;
		}
	},

	/**
	 * Verarbeitet einen CDM-Artikel (Combined Mode).
	 * Fügt die URL neben dem Autor in der Header-Zeile ein.
	 */
	enhanceCdmRow: function (row) {
		if (row.dataset.ciUrlDone) return;
		row.dataset.ciUrlDone = '1';

		if (typeof CI_CONFIG !== 'undefined' && !CI_CONFIG.showUrl) return;

		const header = row.querySelector('.header');
		if (!header) return;

		// URL aus dem Titel-Link extrahieren
		const titleLink = header.querySelector('a.title');
		if (!titleLink) return;

		const url = titleLink.getAttribute('href');
		if (!url || url === '#') return;

		// URL-Element erstellen
		const urlEl = document.createElement('a');
		urlEl.className = 'ci-article-url';
		urlEl.href = url;
		urlEl.target = '_blank';
		urlEl.rel = 'noopener noreferrer';
		urlEl.textContent = CompactImages.formatUrl(url);
		urlEl.title = url;

		// Nach dem Autor einfügen (oder nach dem titleWrap)
		const author = header.querySelector('.author');
		const titleWrap = header.querySelector('.titleWrap');

		if (author && author.parentNode) {
			author.parentNode.insertBefore(urlEl, author.nextSibling);
		} else if (titleWrap && titleWrap.parentNode) {
			titleWrap.parentNode.insertBefore(urlEl, titleWrap.nextSibling);
		}
	},

	/**
	 * Verarbeitet einen Drei-Panel-Artikel.
	 * Fügt die URL als eigene Zeile unter dem Titel ein.
	 */
	enhancePostView: function (post) {
		if (post.dataset.ciUrlDone) return;
		post.dataset.ciUrlDone = '1';

		if (typeof CI_CONFIG !== 'undefined' && !CI_CONFIG.showUrl) return;

		const header = post.querySelector('.header');
		if (!header) return;

		const titleLink = header.querySelector('.title a') || header.querySelector('a.title');
		if (!titleLink) return;

		const url = titleLink.getAttribute('href');
		if (!url || url === '#') return;

		// URL-Zeile erstellen
		const urlRow = document.createElement('div');
		urlRow.className = 'ci-url-row';

		const urlEl = document.createElement('a');
		urlEl.className = 'ci-article-url';
		urlEl.href = url;
		urlEl.target = '_blank';
		urlEl.rel = 'noopener noreferrer';
		urlEl.textContent = CompactImages.formatUrl(url);
		urlEl.title = url;

		urlRow.appendChild(urlEl);

		// Nach der ersten Row (Titel-Zeile) einfügen
		const firstRow = header.querySelector('.row');
		if (firstRow && firstRow.parentNode) {
			firstRow.parentNode.insertBefore(urlRow, firstRow.nextSibling);
		}
	},

	/**
	 * Kompaktiert das erste große Bild in einem .attachments-inline Container.
	 * Enclosure-Bilder werden von JS gerendert, daher muss die
	 * Thumbnail-Umwandlung client-seitig erfolgen.
	 */
	compactEnclosureImages: function (container) {
		var inlineContainers = container
			? container.querySelectorAll('.attachments-inline:not([data-ci-enc-done])')
			: document.querySelectorAll('.attachments-inline:not([data-ci-enc-done])');

		inlineContainers.forEach(function (el) {
			el.dataset.ciEncDone = '1';

			// Erstes Bild im Container finden
			var firstImg = el.querySelector('img');
			if (!firstImg) return;

			var src = firstImg.getAttribute('src') || '';
			// Data-URIs und Tracking-Pixel ueberspringen
			if (src.indexOf('data:') === 0) return;

			// Prüfe ob Bild groß genug ist (wenn width-Attribut vorhanden)
			var width = parseInt(firstImg.getAttribute('width'), 10);
			if (width > 0 && width < 200) return;

			// Elternelement (das <p>) finden
			var parentP = firstImg.parentNode;

			// Wrapper erstellen (gleiche Klasse wie PHP-seitig)
			var wrapper = document.createElement('div');
			wrapper.className = 'ci-thumb-wrap';

			// Bild klonen ohne width/height
			var clonedImg = firstImg.cloneNode(true);
			clonedImg.removeAttribute('width');
			clonedImg.removeAttribute('height');

			wrapper.appendChild(clonedImg);

			// Wenn das <p> nur das Bild enthält, ersetze das gesamte <p>
			if (parentP && parentP.nodeName === 'P') {
				var hasOtherContent = false;
				for (var i = 0; i < parentP.childNodes.length; i++) {
					var child = parentP.childNodes[i];
					if (child === firstImg) continue;
					if (child.nodeType === 3 && child.textContent.trim() === '') continue;
					hasOtherContent = true;
					break;
				}
				if (!hasOtherContent) {
					parentP.parentNode.insertBefore(wrapper, parentP);
					parentP.parentNode.removeChild(parentP);
					return;
				}
			}

			// Sonst nur das Bild ersetzen
			firstImg.parentNode.insertBefore(wrapper, firstImg);
			firstImg.parentNode.removeChild(firstImg);
		});
	},

	/**
	 * Alle sichtbaren Artikel verarbeiten.
	 */
	processAll: function () {
		// CDM-Ansicht
		document.querySelectorAll('.cdm:not([data-ci-url-done])').forEach(function (row) {
			CompactImages.enhanceCdmRow(row);
		});

		// Drei-Panel-Ansicht
		document.querySelectorAll('.post:not([data-ci-url-done])').forEach(function (post) {
			CompactImages.enhancePostView(post);
		});

		// Enclosure-Bilder kompaktieren
		CompactImages.compactEnclosureImages();
	},

	/**
	 * Initialisierung: MutationObserver für dynamisch geladene Artikel.
	 */
	init: function () {
		// Initiale Verarbeitung
		CompactImages.processAll();

		// Beobachte DOM-Änderungen für dynamisch geladene Artikel
		const observer = new MutationObserver(function (mutations) {
			let shouldProcess = false;
			for (const m of mutations) {
				if (m.addedNodes.length > 0) {
					shouldProcess = true;
					break;
				}
			}
			if (shouldProcess) {
				CompactImages.processAll();
			}
		});

		// Hauptcontainer beobachten
		const container = document.getElementById('headlines-frame')
			|| document.getElementById('content-insert')
			|| document.body;

		observer.observe(container, { childList: true, subtree: true });
	}
};

// Starten sobald das DOM bereit ist
if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', CompactImages.init);
} else {
	CompactImages.init();
}
