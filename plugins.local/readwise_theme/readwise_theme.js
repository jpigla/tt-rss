/* ═══════════════════════════════════════════════════════════════
   Readwise Theme — Enhanced Headlines
   ═══════════════════════════════════════════════════════════════
   Liest die per PHP injizierten Metadaten (JSON in <script>) und
   baut eine zweite Zeile im CDM/HL-Header: Domain, Autor, Lesezeit, Tags.

   Sicherheitshinweis: Alle Texte werden via textContent gesetzt (kein
   innerHTML mit User-Daten). Die JSON-Metadaten kommen aus dem
   vertrauenswürdigen PHP-Backend (bereits HTML-escaped).
   ═══════════════════════════════════════════════════════════════ */

'use strict';

const ReadwiseTheme = {

	/**
	 * Erzeugt ein DOM-Element mit optionalen Klassen und Textinhalt.
	 */
	el: function (tag, className, text) {
		const e = document.createElement(tag);
		if (className) e.className = className;
		if (text) e.textContent = text;
		return e;
	},

	/**
	 * Verarbeitet einen einzelnen CDM-Artikel und fügt die Meta-Zeile ein.
	 */
	enhanceRow: function (row) {
		if (row.dataset.rwEnhanced) return;
		row.dataset.rwEnhanced = '1';

		const header = row.querySelector('.header');
		if (!header) return;

		// Metadaten laden: entweder aus DOM (expanded) oder aus data-content (packed)
		let meta = null;

		// 1. Versuch: Script-Tag im DOM (Content bereits entpackt)
		const metaScript = row.querySelector('script.rw-meta-data');
		if (metaScript) {
			try { meta = JSON.parse(metaScript.textContent); } catch (e) { /* weiter */ }
		}

		// 2. Versuch: Content ist gepackt im data-content Attribut
		if (!meta && row.dataset.content) {
			const parser = new DOMParser();
			const doc = parser.parseFromString(row.dataset.content, 'text/html');
			const packed = doc.querySelector('script.rw-meta-data');
			if (packed) {
				try { meta = JSON.parse(packed.textContent); } catch (e) { /* weiter */ }
			}
		}

		if (!meta) return;

		// Bestehende Elemente im Header ausblenden (Feed-Name, Author)
		const feedEl = header.querySelector('.feed');
		const authorEl = header.querySelector('.author');
		if (feedEl) feedEl.classList.add('rw-hidden');
		if (authorEl) authorEl.classList.add('rw-hidden');

		// Meta-Zeile aufbauen
		const metaLine = ReadwiseTheme.el('div', 'rw-meta-line');
		const infoSpan = ReadwiseTheme.el('span', 'rw-meta-info');

		let partCount = 0;

		// Favicon + Domain
		if (meta.domain) {
			const domainSpan = ReadwiseTheme.el('span', 'rw-meta-domain');
			const feedId = meta.feed_id || parseInt(row.dataset.origFeedId) || 0;

			if (meta.has_icon && feedId) {
				const img = document.createElement('img');
				img.className = 'rw-meta-favicon';
				img.width = 14;
				img.height = 14;
				img.alt = '';
				img.src = (typeof App !== 'undefined'
					? App.getInitParam('icons_url') + '?op=feed_icon&id=' + feedId
					: 'feed-icons/' + feedId + '.ico');
				img.onerror = function () { this.style.display = 'none'; };
				domainSpan.appendChild(img);
			} else {
				const icon = ReadwiseTheme.el('i', 'material-icons rw-meta-icon', 'language');
				domainSpan.appendChild(icon);
			}

			domainSpan.appendChild(document.createTextNode(' ' + meta.domain));
			infoSpan.appendChild(domainSpan);
			partCount++;
		}

		// Autor
		if (meta.author) {
			if (partCount > 0) infoSpan.appendChild(ReadwiseTheme.el('span', 'rw-meta-sep', '\u00B7'));
			infoSpan.appendChild(ReadwiseTheme.el('span', 'rw-meta-author', meta.author));
			partCount++;
		}

		// Lesezeit
		if (meta.reading_time > 0) {
			if (partCount > 0) infoSpan.appendChild(ReadwiseTheme.el('span', 'rw-meta-sep', '\u00B7'));
			const timeSpan = ReadwiseTheme.el('span', 'rw-meta-time');
			timeSpan.appendChild(ReadwiseTheme.el('i', 'material-icons', 'schedule'));
			const label = meta.reading_time === 1 ? ' 1 Min.' : ' ' + meta.reading_time + ' Min.';
			timeSpan.appendChild(document.createTextNode(label));
			infoSpan.appendChild(timeSpan);
		}

		metaLine.appendChild(infoSpan);

		// Tags als Badges
		if ((meta.tags && meta.tags.length > 0) || (meta.labels && meta.labels.length > 0)) {
			const tagsSpan = ReadwiseTheme.el('span', 'rw-meta-tags');

			// Reguläre Tags
			if (meta.tags) {
				const maxTags = 4;
				const visible = meta.tags.slice(0, maxTags);
				visible.forEach(function (tag) {
					const a = ReadwiseTheme.el('a', 'rw-meta-tag', tag);
					a.href = '#';
					a.onclick = function (e) {
						e.stopPropagation();
						e.preventDefault();
						if (typeof Feeds !== 'undefined') Feeds.open({ feed: tag.trim() });
					};
					tagsSpan.appendChild(a);
				});

				if (meta.tags.length > maxTags) {
					tagsSpan.appendChild(
						ReadwiseTheme.el('span', 'rw-meta-tag rw-meta-tag-more', '+' + (meta.tags.length - maxTags))
					);
				}
			}

			// Labels (haben eigene Farben)
			if (meta.labels) {
				meta.labels.forEach(function (label) {
					const a = ReadwiseTheme.el('a', 'rw-meta-label', label[1]);
					a.href = '#';
					a.style.color = label[2];
					a.style.backgroundColor = label[3];
					a.onclick = function (e) {
						e.stopPropagation();
						e.preventDefault();
						if (typeof Feeds !== 'undefined') Feeds.open({ feed: label[0] });
					};
					tagsSpan.appendChild(a);
				});
			}

			metaLine.appendChild(tagsSpan);
		}

		// Meta-Zeile nach dem Header einfügen
		header.insertAdjacentElement('afterend', metaLine);
	},

	/**
	 * Alle bestehenden CDM-Zeilen verarbeiten.
	 */
	processExisting: function () {
		document.querySelectorAll('.cdm:not([data-rw-enhanced])').forEach(function (row) {
			ReadwiseTheme.enhanceRow(row);
		});
	},

	/**
	 * MutationObserver für dynamisch nachgeladene Headlines.
	 */
	init: function () {
		const frame = document.getElementById('headlines-frame');
		if (!frame) return;

		// Bestehende Zeilen verarbeiten
		ReadwiseTheme.processExisting();

		// Auf neue Zeilen reagieren
		const observer = new MutationObserver(function (mutations) {
			for (const mutation of mutations) {
				for (const node of mutation.addedNodes) {
					if (node.nodeType !== Node.ELEMENT_NODE) continue;

					if (node.classList && node.classList.contains('cdm')) {
						ReadwiseTheme.enhanceRow(node);
					}

					// Auch Kinder durchsuchen (z.B. bei Container-Einfügungen)
					if (node.querySelectorAll) {
						node.querySelectorAll('.cdm:not([data-rw-enhanced])').forEach(function (row) {
							ReadwiseTheme.enhanceRow(row);
						});
					}
				}
			}
		});

		observer.observe(frame, { childList: true, subtree: true });
	}
};

// Initialisierung
if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', ReadwiseTheme.init);
} else {
	ReadwiseTheme.init();
}
