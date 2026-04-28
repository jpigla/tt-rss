/* ═══════════════════════════════════════════════════════════════
   Readwise Theme — Enhanced Headlines
   ═══════════════════════════════════════════════════════════════
   Zweizeilige CDM-Ansicht im Readwise-Stil:
   - Zeile 1: Titel (externer Link)
   - Zeile 2: Domain+Favicon, Autor, Lesezeit, Labels
   - Accordion: nur ein Eintrag gleichzeitig offen
   - Lesezeit wird erst nach Content-Unpack berechnet

   Sicherheit: Alle Texte via textContent (kein innerHTML mit User-Daten).
   ═══════════════════════════════════════════════════════════════ */

'use strict';

const ReadwiseTheme = {

	/** Wörter pro Minute für Lesezeit-Berechnung */
	WPM: 200,

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
	 * Berechnet die Lesezeit aus DOM-Content (nach Unpack).
	 */
	calculateReadingTime: function (contentEl) {
		if (!contentEl) return 0;
		const text = (contentEl.textContent || '').trim();
		if (!text || text.length < 50) return 0;
		const words = text.split(/\s+/).length;
		return Math.max(1, Math.ceil(words / ReadwiseTheme.WPM));
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

		const metaScript = row.querySelector('script.rw-meta-data');
		if (metaScript) {
			try { meta = JSON.parse(metaScript.textContent); } catch (e) { /* weiter */ }
		}

		if (!meta && row.dataset.content) {
			const parser = new DOMParser();
			const doc = parser.parseFromString(row.dataset.content, 'text/html');
			const packed = doc.querySelector('script.rw-meta-data');
			if (packed) {
				try { meta = JSON.parse(packed.textContent); } catch (e) { /* weiter */ }
			}
		}

		if (!meta) return;

		// Bestehende Elemente ausblenden
		const feedEl = header.querySelector('.feed');
		const authorEl = header.querySelector('.author');
		if (feedEl) feedEl.classList.add('rw-hidden');
		if (authorEl) authorEl.classList.add('rw-hidden');

		// Labels im titleWrap ausblenden (werden in Metazeile verschoben)
		const labelsInTitle = header.querySelector('.titleWrap .labels');
		if (labelsInTitle) labelsInTitle.classList.add('rw-hidden');

		// Meta-Zeile aufbauen
		const metaLine = ReadwiseTheme.el('div', 'rw-meta-line');
		const feedId = meta.feed_id || parseInt(row.dataset.origFeedId) || 0;

		// Klick auf Meta-Zeile soll Artikel aufklappen (nicht auf Links)
		metaLine.addEventListener('click', function (e) {
			if (e.target.closest('a')) return;
			e.stopPropagation();
			const articleId = parseInt(row.dataset.articleId);
			if (articleId) ReadwiseTheme.toggleArticle(articleId, row);
		});

		const infoSpan = ReadwiseTheme.el('span', 'rw-meta-info');
		let partCount = 0;

		// Favicon + Feed-Name (klickbar → öffnet Feed)
		const feedTitle = row.dataset.origFeedTitle || meta.domain || '';
		if (feedTitle || meta.domain) {
			const domainLink = document.createElement('a');
			domainLink.className = 'rw-meta-domain';
			domainLink.href = '#';
			domainLink.title = 'Nur Artikel von ' + (feedTitle || meta.domain) + ' anzeigen';
			domainLink.addEventListener('click', function (e) {
				e.stopPropagation();
				e.preventDefault();
				if (typeof Feeds !== 'undefined' && feedId) {
					Feeds.open({ feed: feedId });
				}
			});

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
				domainLink.appendChild(img);
			} else {
				domainLink.appendChild(ReadwiseTheme.el('i', 'material-icons rw-meta-icon', 'language'));
			}

			domainLink.appendChild(document.createTextNode(' ' + (feedTitle || meta.domain)));
			infoSpan.appendChild(domainLink);
			partCount++;
		}

		// Autor
		if (meta.author) {
			if (partCount > 0) infoSpan.appendChild(ReadwiseTheme.el('span', 'rw-meta-sep', '\u00B7'));
			infoSpan.appendChild(ReadwiseTheme.el('span', 'rw-meta-author', meta.author));
			partCount++;
		}

		// Lesezeit-Platzhalter (wird nach Unpack gefüllt)
		const timePlaceholder = ReadwiseTheme.el('span', 'rw-meta-time rw-meta-time-pending');
		timePlaceholder.style.display = 'none';
		if (partCount > 0) {
			const sep = ReadwiseTheme.el('span', 'rw-meta-sep rw-meta-time-sep', '\u00B7');
			sep.style.display = 'none';
			infoSpan.appendChild(sep);
		}
		infoSpan.appendChild(timePlaceholder);

		metaLine.appendChild(infoSpan);

		// Labels als einheitliche Badges (nicht bunt — Theme-konform)
		if (meta.labels && meta.labels.length > 0) {
			const labelsSpan = ReadwiseTheme.el('span', 'rw-meta-tags');

			meta.labels.forEach(function (label) {
				const a = ReadwiseTheme.el('a', 'rw-meta-tag', label[1]);
				a.href = '#';
				a.addEventListener('click', function (e) {
					e.stopPropagation();
					e.preventDefault();
					if (typeof Feeds !== 'undefined') Feeds.open({ feed: label[0] });
				});
				labelsSpan.appendChild(a);
			});

			metaLine.appendChild(labelsSpan);
		}

		// Meta-Zeile nach dem Header einfügen
		header.insertAdjacentElement('afterend', metaLine);

		// Klick-Handler auf den Header-Bereich (ohne Titel-Link)
		ReadwiseTheme.setupClickHandler(row, header);
	},

	/**
	 * Richtet den Klick-Handler ein: Klick auf Header (nicht Titel) klappt auf/zu.
	 * Titel-Link bleibt als externer Link erhalten.
	 */
	setupClickHandler: function (row, header) {
		// titleWrap-onclick entfernen (verhindert Standardverhalten)
		const titleWrap = header.querySelector('.titleWrap');
		if (titleWrap) {
			titleWrap.removeAttribute('onclick');

			// Klick auf titleWrap (nicht auf <a class="title">) → Toggle
			titleWrap.addEventListener('click', function (e) {
				// Klick auf den Titel-Link → durchlassen (öffnet Original)
				if (e.target.closest('a.title')) return;

				e.stopPropagation();
				e.preventDefault();
				const articleId = parseInt(row.dataset.articleId);
				if (articleId) ReadwiseTheme.toggleArticle(articleId, row);
			});
		}

		// Header left/right Bereiche: Klick auf Icons beibehalten
		const headerLeft = header.querySelector('.left');
		if (headerLeft) {
			headerLeft.addEventListener('click', function (e) {
				// Nur bei Klick auf den leeren Bereich (nicht Icons/Checkboxen)
				if (e.target === headerLeft) {
					e.stopPropagation();
					const articleId = parseInt(row.dataset.articleId);
					if (articleId) ReadwiseTheme.toggleArticle(articleId, row);
				}
			});
		}
	},

	/**
	 * Accordion-Toggle: Artikel auf-/zuklappen.
	 * - Wenn der Artikel bereits offen ist → zuklappen
	 * - Wenn ein anderer offen ist → diesen zuklappen, neuen öffnen
	 */
	toggleArticle: function (id, row) {
		const currentActive = typeof Article !== 'undefined' ? Article.getActive() : 0;

		if (currentActive === id) {
			// Zuklappen: active entfernen, Content zurückpacken
			row.classList.remove('active');

			if (typeof Article !== 'undefined' && typeof App !== 'undefined'
				&& App.isCombinedMode() && !App.getInitParam('cdm_expanded')) {
				Article.pack(row);
			}

			// Aktiven Artikel zurücksetzen (kein Artikel mehr aktiv)
			// Wir setzen post_under_pointer auf false, damit kein Artikel als aktiv gilt
			if (typeof Article !== 'undefined') {
				Article.post_under_pointer = false;
				// Direkt den internen State zurücksetzen, indem wir die active-Klasse entfernen
				// Article hat keinen expliziten "clearActive", daher reicht das DOM-Update
			}
		} else {
			// Neuen Artikel öffnen (setActive schließt automatisch den vorherigen)
			if (typeof Article !== 'undefined') {
				Article.setActive(id);
			}

			// Lesezeit nach Unpack berechnen
			ReadwiseTheme.updateReadingTime(row);
		}
	},

	/**
	 * Berechnet und zeigt die Lesezeit nach dem Entpacken des Contents.
	 */
	updateReadingTime: function (row) {
		const timePlaceholder = row.querySelector('.rw-meta-time-pending');
		const timeSep = row.querySelector('.rw-meta-time-sep');
		if (!timePlaceholder) return;

		// Kurz warten bis Content entpackt ist
		setTimeout(function () {
			const contentInner = row.querySelector('.content-inner');
			if (!contentInner) return;

			const minutes = ReadwiseTheme.calculateReadingTime(contentInner);
			if (minutes > 0) {
				timePlaceholder.appendChild(ReadwiseTheme.el('i', 'material-icons', 'schedule'));
				const label = minutes === 1 ? ' 1 Min.' : ' ' + minutes + ' Min.';
				timePlaceholder.appendChild(document.createTextNode(label));
				timePlaceholder.style.display = '';
				timePlaceholder.classList.remove('rw-meta-time-pending');
				if (timeSep) timeSep.style.display = '';
			}

			// Enclosure-Fallback prüfen
			ReadwiseTheme.handleEnclosureFallback(contentInner);
		}, 100);
	},

	/**
	 * Alle bestehenden CDM-Zeilen verarbeiten.
	 */
	processExisting: function () {
		document.querySelectorAll('.cdm:not([data-rw-enhanced])').forEach(function (row) {
			ReadwiseTheme.enhanceRow(row);
		});
		document.querySelectorAll('.hl:not([data-rw-hl-done])').forEach(function (row) {
			ReadwiseTheme.enhanceHlRow(row);
		});
	},

	/**
	 * MutationObserver für dynamisch nachgeladene Headlines.
	 */
	/** Zielname für den Starred-Ordner */
	STARRED_NAME: 'Später lesen',

	/** Originalnamen, die ersetzt werden */
	STARRED_ORIGINALS: ['Starred articles', 'Markierte Artikel', 'Gespeicherte Artikel'],

	/**
	 * Benennt "Starred articles" überall in "Später lesen" um.
	 * Beobachtet Feed-Tree und Toolbar dauerhaft.
	 */
	renameStarredFolder: function () {
		var self = ReadwiseTheme;

		var rename = function () {
			// Feed-Tree Label
			var row = document.querySelector('#feedTree .dijitTreeRow[data-feed-id="-1"][data-is-cat="false"]');
			if (row) {
				var label = row.querySelector('.dijitTreeLabel');
				if (label && label.textContent !== self.STARRED_NAME && self.STARRED_ORIGINALS.indexOf(label.textContent) !== -1) {
					label.textContent = self.STARRED_NAME;
				}
			}

			// Toolbar feed_title (span und a)
			document.querySelectorAll('.feed_title').forEach(function (el) {
				if (self.STARRED_ORIGINALS.indexOf(el.textContent) !== -1) {
					el.textContent = self.STARRED_NAME;
				}
			});

			// Dojo-Dialoge (Titel, Header, Content)
			document.querySelectorAll('.dijitDialogTitle, .dijitDialog header, .dijitDialog .dijitDialogPaneContent header').forEach(function (el) {
				self.STARRED_ORIGINALS.forEach(function (orig) {
					if (el.textContent.indexOf(orig) !== -1) {
						el.childNodes.forEach(function (node) {
							if (node.nodeType === Node.TEXT_NODE && node.textContent.indexOf(orig) !== -1) {
								node.textContent = node.textContent.replace(orig, self.STARRED_NAME);
							}
						});
						// Falls kein Text-Node direkt, innerHTML-Ersetzung als Fallback
						if (el.textContent.indexOf(orig) !== -1) {
							el.textContent = el.textContent.replace(orig, self.STARRED_NAME);
						}
					}
				});
			});
		};

		// Sofort und dann per Observer
		rename();

		var obs = new MutationObserver(rename);
		obs.observe(document.body, { childList: true, subtree: true, characterData: true });
	},

	/**
	 * Zeigt die vollständige Artikel-URL unter dem Titel im ContentPane (3-Panel-Ansicht).
	 */
	enhancePostView: function (post) {
		if (post.dataset.rwPostDone) return;
		post.dataset.rwPostDone = '1';

		var titleLink = post.querySelector('.header .title a');
		if (!titleLink || !titleLink.href) return;

		var urlLink = document.createElement('a');
		urlLink.className = 'post-article-url';
		urlLink.href = titleLink.href;
		urlLink.target = '_blank';
		urlLink.rel = 'noopener noreferrer';
		urlLink.textContent = titleLink.href;
		titleLink.insertAdjacentElement('afterend', urlLink);
	},

	/**
	 * Prüft ob ein Artikel Content-Bilder hat. Falls nicht, werden Enclosure-Bilder angezeigt.
	 */
	handleEnclosureFallback: function (container) {
		if (!container) return;
		const enclosures = container.querySelector('.attachments-inline');
		if (!enclosures) return;

		// Bilder im Content zählen (ohne die in .attachments-inline)
		const contentImages = container.querySelectorAll('img:not(.attachments-inline img)');
		const hasContentImages = Array.from(contentImages).some(function (img) {
			return !img.closest('.attachments-inline');
		});

		if (!hasContentImages) {
			enclosures.classList.add('rw-show-enclosures');
		}
	},

	/**
	 * Escape-Taste schließt den geöffneten Artikel.
	 */
	bindEscapeClose: function () {
		document.addEventListener('keydown', function (e) {
			if (e.key !== 'Escape') return;
			if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.isContentEditable) return;

			if (typeof App !== 'undefined' && typeof Article !== 'undefined') {
				if (App.isCombinedMode()) {
					Article.cdmUnsetActive();
				} else {
					Article.close();
				}
				e.stopPropagation();
				e.preventDefault();
			}
		});
	},

	/**
	 * Erweitert eine .hl-Zeile (Drei-Panel-Modus) mit Readwise-Layout:
	 * - Unread-Dot hinzufuegen
	 * - Favicon klickbar machen und nach links verschieben
	 * - Preview-Text aus dem Titel entfernen
	 * - Labels aus dem Titel herausziehen
	 * - Feed-Link und Score-Bereich ausblenden
	 */
	enhanceHlRow: function (row) {
		if (row.dataset.rwHlDone) return;
		row.dataset.rwHlDone = '1';

		var left = row.querySelector('.left');
		if (!left) return;

		// 1. Unread-Dot einfuegen (nach Checkbox, vor star)
		var markedPic = left.querySelector('.marked-pic');
		if (markedPic && !left.querySelector('.hl-unread-dot')) {
			var dot = document.createElement('span');
			dot.className = 'hl-unread-dot';
			var articleId = row.getAttribute('data-article-id');
			dot.setAttribute('title', 'Gelesen/Ungelesen umschalten');
			dot.addEventListener('click', function (e) {
				e.stopPropagation();
				Headlines.toggleUnread(parseInt(articleId));
			});
			left.insertBefore(dot, markedPic);
		}

		// 2. Favicon klickbar machen — aus .right holen und vor .title einfuegen
		var rightDiv = row.querySelector('.right');
		var iconFeed = rightDiv ? rightDiv.querySelector('.icon-feed') : null;
		var titleDiv = row.querySelector('div.title');

		if (iconFeed && titleDiv) {
			var feedId = row.getAttribute('data-orig-feed-id');
			var feedTitle = row.getAttribute('data-orig-feed-title') || '';

			var faviconSpan = iconFeed.cloneNode(true);
			faviconSpan.className = 'icon-feed hl-favicon';
			faviconSpan.setAttribute('title', 'Nur Artikel von ' + feedTitle + ' anzeigen');
			faviconSpan.addEventListener('click', function (e) {
				e.stopPropagation();
				Feeds.open({feed: parseInt(feedId)});
			});
			row.insertBefore(faviconSpan, titleDiv);
		}

		// 3. Preview-Text entfernen
		var preview = row.querySelector('.preview');
		if (preview) preview.remove();

		// 4. Labels aus dem Titel-Div herausziehen
		var hlContent = row.querySelector('.hl-content');
		if (hlContent) {
			var labelsSpan = hlContent.querySelector('.label-list');
			if (labelsSpan) {
				titleDiv.parentNode.insertBefore(labelsSpan, titleDiv.nextSibling);
			}
		}

		// 5. Feed-Link und Score-Bereich ausblenden (CSS-Fallback)
		var feedSpan = row.querySelector('.feed.vfeedMenuAttach');
		if (feedSpan) feedSpan.style.display = 'none';
		if (rightDiv) rightDiv.style.display = 'none';
	},

	init: function () {
		ReadwiseTheme.renameStarredFolder();
		ReadwiseTheme.bindEscapeClose();

		const frame = document.getElementById('headlines-frame');
		if (!frame) return;

		ReadwiseTheme.processExisting();

		const observer = new MutationObserver(function (mutations) {
			for (const mutation of mutations) {
				for (const node of mutation.addedNodes) {
					if (node.nodeType !== Node.ELEMENT_NODE) continue;

					if (node.classList && node.classList.contains('cdm')) {
						ReadwiseTheme.enhanceRow(node);
					}

					// HL-Zeilen (Drei-Panel-Modus) erweitern
					if (node.classList && node.classList.contains('hl')) {
						ReadwiseTheme.enhanceHlRow(node);
					}

					if (node.querySelectorAll) {
						node.querySelectorAll('.cdm:not([data-rw-enhanced])').forEach(function (row) {
							ReadwiseTheme.enhanceRow(row);
						});
						node.querySelectorAll('.hl:not([data-rw-hl-done])').forEach(function (row) {
							ReadwiseTheme.enhanceHlRow(row);
						});
					}
				}
			}
		});

		observer.observe(frame, { childList: true, subtree: true });

		// 3-Panel: URL + Enclosure-Fallback für .post-Ansicht
		const contentInsert = document.getElementById('content-insert');
		if (contentInsert) {
			const postObserver = new MutationObserver(function () {
				const post = contentInsert.querySelector('.post');
				if (post) {
					ReadwiseTheme.enhancePostView(post);
					const content = post.querySelector('div.content');
					if (content) ReadwiseTheme.handleEnclosureFallback(content);
				}
			});
			postObserver.observe(contentInsert, { childList: true, subtree: true });
		}
	}
};

// Initialisierung
if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', ReadwiseTheme.init);
} else {
	ReadwiseTheme.init();
}
