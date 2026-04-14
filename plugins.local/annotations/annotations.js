/* global require, Plugins, PluginHost, xhr, App, Notify, fox, __ */

/**
 * Hauptplugin — nur auf der Hauptseite (index.php) aktiv.
 */
require(['dojo/_base/kernel', 'dojo/ready'], function (dojo, ready) {
	ready(function () {

		// Auf prefs.php existiert kein PluginHost — dort brauchen wir den Hauptteil nicht.
		if (typeof PluginHost === 'undefined') return;

		Plugins.Annotations = {

			colors: ['#fff3cd', '#d4edda', '#cce5ff', '#f8d7da', '#e2d5f1', '#ffeeba'],

			_wrapping: false,
			_observer: null,

			init: function () {
				// Text-Selektion in Annotation-Wrappern
				document.addEventListener('mouseup', function (e) {
					// Klick innerhalb Toolbar/Popup → ignorieren
					if (e.target.closest('.ann-context-toolbar') || e.target.closest('.ann-popup')) return;

					// Klick auf bestehende Annotation → Bearbeiten
					var mark = e.target.closest('mark.ann-highlight');
					if (mark) {
						var wrapper = mark.closest('.annotations-wrapper');
						if (wrapper) {
							Plugins.Annotations.editHighlight(mark, wrapper);
							return;
						}
					}

					var wrapper = e.target.closest('.annotations-wrapper');
					if (!wrapper) return;

					var sel = window.getSelection();
					if (!sel || sel.isCollapsed || !sel.toString().trim()) return;

					// Reader-Ansicht (content-insert) → Kontext-Toolbar zeigen
					var isReader = !!wrapper.closest('#content-insert');
					if (isReader) {
						Plugins.Annotations.showContextToolbar(sel, wrapper);
					} else {
						Plugins.Annotations.showPopup(sel, wrapper);
					}
				});

				Plugins.Annotations._setupObserver();
				Plugins.Annotations.applyAll();
			},

			_setupObserver: function () {
				var self = Plugins.Annotations;

				var callback = function (mutations) {
					if (self._wrapping) return;

					for (var i = 0; i < mutations.length; i++) {
						var mutation = mutations[i];
						if (mutation.type !== 'childList' || mutation.addedNodes.length === 0) continue;

						var target = mutation.target;
						if (!target.classList) continue;

						var isContentInner = target.classList.contains('content-inner');
						var isPostContent = target.classList.contains('content') && target.closest('.post');

						if (!isContentInner && !isPostContent) continue;
						if (target.querySelector('.annotations-wrapper')) continue;
						if (target.textContent.trim().length < 50) continue;

						var parent = target.closest('[data-article-id]');
						if (!parent) continue;

						self._ensureWrapper(target, parent.dataset.articleId);
					}
				};

				var observer = new MutationObserver(callback);

				var targets = [
					document.getElementById('headlines-frame'),
					document.getElementById('content-insert')
				];

				targets.forEach(function (el) {
					if (el) observer.observe(el, { childList: true, subtree: true });
				});

				self._observer = observer;
			},

			_ensureWrapper: function (contentEl, articleId) {
				var self = Plugins.Annotations;
				if (self._wrapping) return;
				if (contentEl.querySelector('.annotations-wrapper')) return;

				self._wrapping = true;

				var wrapper = document.createElement('div');
				wrapper.className = 'annotations-wrapper';
				wrapper.setAttribute('data-annotations', '[]');
				wrapper.setAttribute('data-article-id', articleId);

				while (contentEl.firstChild) {
					wrapper.appendChild(contentEl.firstChild);
				}
				contentEl.appendChild(wrapper);

				self._wrapping = false;

				self._fetchAndApply(articleId, wrapper);
			},

			_fetchAndApply: function (articleId, wrapper) {
				xhr.json("backend.php", App.getPhArgs("annotations", "get_annotations", {
					article_id: articleId
				}), function (reply) {
					if (reply && reply.length > 0) {
						wrapper.setAttribute('data-annotations', JSON.stringify(reply));
						Plugins.Annotations.applyHighlights(wrapper, reply);
					}
				});
			},

			applyAll: function () {
				document.querySelectorAll('.annotations-wrapper[data-annotations]').forEach(function (el) {
					try {
						if (el.querySelector('mark.ann-highlight')) return;

						var annotations = JSON.parse(el.getAttribute('data-annotations'));
						if (annotations && annotations.length > 0) {
							Plugins.Annotations.applyHighlights(el, annotations);
						}
					} catch (e) { /* ignorieren */ }
				});
			},

			/**
			 * Highlights anwenden — mit Kontextprüfung zur Vermeidung falscher Treffer.
			 */
			applyHighlights: function (wrapper, annotations) {
				var matched = 0;
				var unmatched = 0;

				annotations.forEach(function (ann) {
					var textContent = ann.highlighted_text;
					if (!textContent) return;

					if (wrapper.querySelector('mark[data-ann-id="' + ann.id + '"]')) {
						matched++;
						return;
					}

					var context = null;
					if (ann.selector_path) {
						try { context = JSON.parse(ann.selector_path); } catch (e) { /* ignorieren */ }
					}

					var found = false;
					var treeWalker = document.createTreeWalker(
						wrapper, NodeFilter.SHOW_TEXT, null, false
					);

					var node;
					while ((node = treeWalker.nextNode())) {
						if (node.parentElement && node.parentElement.classList &&
							(node.parentElement.classList.contains('ann-highlight') ||
							 node.parentElement.classList.contains('ann-fulltext-hint'))) continue;

						var searchFrom = 0;
						while (true) {
							var idx = node.textContent.indexOf(textContent, searchFrom);
							if (idx === -1) break;

							// Kontextprüfung: passen die umgebenden Zeichen?
							if (context && context.before && context.before.length >= 5) {
								var beforeInDoc = node.textContent.substring(Math.max(0, idx - 20), idx);
								var checkStr = context.before.slice(-10);
								if (beforeInDoc.indexOf(checkStr) === -1) {
									searchFrom = idx + 1;
									continue;
								}
							}

							try {
								var mark = document.createElement('mark');
								mark.className = 'ann-highlight';
								mark.style.backgroundColor = ann.color || '#fff3cd';
								mark.dataset.annId = ann.id;
								mark.dataset.annNote = ann.note || '';
								mark.dataset.annColor = ann.color || '#fff3cd';
								if (ann.note) {
									mark.title = ann.note;
									mark.classList.add('ann-has-note');
								}

								var range = document.createRange();
								range.setStart(node, idx);
								range.setEnd(node, Math.min(idx + textContent.length, node.textContent.length));
								range.surroundContents(mark);
								found = true;
								matched++;
							} catch (e) { /* DOM-Grenzüberschreitung */ }
							break;
						}
						if (found) break;
					}

					if (!found) unmatched++;
				});

				// Hinweis für nicht gefundene Annotationen (z.B. im Volltext erstellt, aber Excerpt angezeigt)
				var existingHint = wrapper.querySelector('.ann-fulltext-hint');
				if (unmatched > 0) {
					if (!existingHint) {
						existingHint = document.createElement('div');
						existingHint.className = 'ann-fulltext-hint';
						wrapper.insertBefore(existingHint, wrapper.firstChild);
					}
					existingHint.textContent = '';
					var icon = document.createElement('i');
					icon.className = 'material-icons';
					icon.style.cssText = 'font-size:14px;vertical-align:middle;margin-right:4px';
					icon.textContent = 'highlight';
					existingHint.appendChild(icon);
					existingHint.appendChild(document.createTextNode(
						unmatched + ' weitere Annotation' + (unmatched > 1 ? 'en' : '') +
						' nicht sichtbar \u2014 ggf. Volltext laden'
					));
				} else if (existingHint) {
					existingHint.remove();
				}
			},

			/**
			 * Bestehende Annotation bearbeiten (Klick auf Markierung).
			 */
			editHighlight: function (mark, wrapper) {
				Plugins.Annotations.removePopup();

				var annId = mark.dataset.annId;
				var currentNote = mark.dataset.annNote || '';
				var currentColor = mark.dataset.annColor || '#fff3cd';
				var articleId = wrapper.getAttribute('data-article-id');

				var rect = mark.getBoundingClientRect();

				var popup = document.createElement('div');
				popup.className = 'ann-popup';
				popup.style.position = 'fixed';
				popup.style.left = Math.min(rect.left, window.innerWidth - 300) + 'px';
				popup.style.top = (rect.bottom + 5) + 'px';
				popup.style.zIndex = '10000';

				// Vorschau
				var preview = document.createElement('div');
				preview.className = 'ann-popup-preview';
				var highlightedText = mark.textContent;
				preview.textContent = highlightedText.length > 80
					? highlightedText.substring(0, 80) + '\u2026'
					: highlightedText;
				popup.appendChild(preview);

				var colorRow = document.createElement('div');
				colorRow.className = 'ann-color-row';

				Plugins.Annotations.colors.forEach(function (color) {
					var btn = document.createElement('span');
					btn.className = 'ann-color-btn';
					btn.style.backgroundColor = color;
					btn.dataset.color = color;
					if (color === currentColor) btn.classList.add('ann-color-active');
					btn.addEventListener('click', function () {
						popup.querySelectorAll('.ann-color-btn').forEach(function (b) {
							b.classList.remove('ann-color-active');
						});
						btn.classList.add('ann-color-active');
					});
					colorRow.appendChild(btn);
				});

				var noteInput = document.createElement('textarea');
				noteInput.className = 'ann-note-input';
				noteInput.placeholder = __('Notiz hinzufügen (optional)...');
				noteInput.rows = 2;
				noteInput.value = currentNote;

				var btnRow = document.createElement('div');
				btnRow.className = 'ann-btn-row';

				var saveBtn = document.createElement('button');
				saveBtn.className = 'ann-save-btn';
				saveBtn.textContent = __('Aktualisieren');
				saveBtn.addEventListener('click', function () {
					var activeColor = popup.querySelector('.ann-color-active');
					var color = activeColor ? activeColor.dataset.color : currentColor;
					var note = noteInput.value;

					xhr.json("backend.php", App.getPhArgs("annotations", "update_annotation", {
						id: annId,
						note: note,
						color: color
					}), function (reply) {
						if (reply && reply.status === 'ok') {
							Notify.info(__('Annotation aktualisiert'));
							mark.style.backgroundColor = color;
							mark.dataset.annNote = note;
							mark.dataset.annColor = color;
							mark.title = note || '';
							if (note) {
								mark.classList.add('ann-has-note');
							} else {
								mark.classList.remove('ann-has-note');
							}

							// data-annotations aktualisieren
							try {
								var existing = JSON.parse(wrapper.getAttribute('data-annotations') || '[]');
								for (var i = 0; i < existing.length; i++) {
									if (existing[i].id == annId) {
										existing[i].note = note;
										existing[i].color = color;
										break;
									}
								}
								wrapper.setAttribute('data-annotations', JSON.stringify(existing));
							} catch (e) { /* ignorieren */ }
						}
					});

					Plugins.Annotations.removePopup();
				});

				var deleteBtn = document.createElement('button');
				deleteBtn.className = 'ann-delete-btn';
				deleteBtn.textContent = __('Löschen');
				deleteBtn.addEventListener('click', function () {
					Plugins.Annotations.deleteAnn(parseInt(annId), parseInt(articleId));
					Plugins.Annotations.removePopup();
				});

				var cancelBtn = document.createElement('button');
				cancelBtn.className = 'ann-cancel-btn';
				cancelBtn.textContent = __('Abbrechen');
				cancelBtn.addEventListener('click', function () {
					Plugins.Annotations.removePopup();
				});

				btnRow.appendChild(saveBtn);
				btnRow.appendChild(deleteBtn);
				btnRow.appendChild(cancelBtn);

				popup.appendChild(colorRow);
				popup.appendChild(noteInput);
				popup.appendChild(btnRow);
				document.body.appendChild(popup);

				setTimeout(function () {
					document.addEventListener('mousedown', function handler(e) {
						if (!popup.contains(e.target)) {
							Plugins.Annotations.removePopup();
							document.removeEventListener('mousedown', handler, true);
						}
					}, true);
				}, 200);
			},

			/**
			 * Schwebendes Kontext-Toolbar über der Textauswahl (nur Reader-Ansicht).
			 */
			showContextToolbar: function (sel, wrapper) {
				Plugins.Annotations.removeContextToolbar();
				Plugins.Annotations.removePopup();

				var range = sel.getRangeAt(0);
				var rect = range.getBoundingClientRect();
				var selectedText = sel.toString().trim();
				if (!selectedText) return;

				var articleId = wrapper.getAttribute('data-article-id');

				// Kontext für spätere Zuordnung
				var beforeText = '';
				try {
					var startNode = range.startContainer;
					beforeText = startNode.textContent.substring(
						Math.max(0, range.startOffset - 20), range.startOffset);
				} catch (e) { /* ignorieren */ }

				var toolbar = document.createElement('div');
				toolbar.className = 'ann-context-toolbar';

				// Position über der Auswahl zentrieren
				var toolbarWidth = 168;
				var left = rect.left + (rect.width / 2) - (toolbarWidth / 2);
				left = Math.max(8, Math.min(left, window.innerWidth - toolbarWidth - 8));

				toolbar.style.position = 'fixed';
				toolbar.style.left = left + 'px';
				toolbar.style.top = (rect.top - 48) + 'px';
				toolbar.style.zIndex = '10001';

				// Falls Toolbar über dem Viewport-Rand → unter die Auswahl
				if (rect.top - 48 < 4) {
					toolbar.style.top = (rect.bottom + 8) + 'px';
					toolbar.classList.add('ann-toolbar-below');
				}

				// SVG-Icons als DOM-Elemente erstellen
				function createHighlightIcon() {
					var ns = 'http://www.w3.org/2000/svg';
					var svg = document.createElementNS(ns, 'svg');
					svg.setAttribute('viewBox', '0 0 24 24');
					svg.setAttribute('width', '18');
					svg.setAttribute('height', '18');
					svg.setAttribute('fill', 'currentColor');
					var path = document.createElementNS(ns, 'path');
					path.setAttribute('d', 'M11 7h6l-7 10v-6H4l7-10v6z');
					svg.appendChild(path);
					return svg;
				}

				function createNoteIcon() {
					var ns = 'http://www.w3.org/2000/svg';
					var svg = document.createElementNS(ns, 'svg');
					svg.setAttribute('viewBox', '0 0 24 24');
					svg.setAttribute('width', '18');
					svg.setAttribute('height', '18');
					svg.setAttribute('fill', 'currentColor');
					var path = document.createElementNS(ns, 'path');
					path.setAttribute('d', 'M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H6l-2 2V4h16v12z');
					svg.appendChild(path);
					return svg;
				}

				function createTagIcon() {
					var ns = 'http://www.w3.org/2000/svg';
					var svg = document.createElementNS(ns, 'svg');
					svg.setAttribute('viewBox', '0 0 24 24');
					svg.setAttribute('width', '18');
					svg.setAttribute('height', '18');
					svg.setAttribute('fill', 'currentColor');
					var path = document.createElementNS(ns, 'path');
					path.setAttribute('d', 'M21.41 11.58l-9-9C12.05 2.22 11.55 2 11 2H4c-1.1 0-2 .9-2 2v7c0 .55.22 1.05.59 1.42l9 9c.36.36.86.58 1.41.58s1.05-.22 1.41-.59l7-7c.37-.36.59-.86.59-1.41s-.23-1.06-.59-1.42zM5.5 7C4.67 7 4 6.33 4 5.5S4.67 4 5.5 4 7 4.67 7 5.5 6.33 7 5.5 7z');
					svg.appendChild(path);
					return svg;
				}

				function createMoreIcon() {
					var ns = 'http://www.w3.org/2000/svg';
					var svg = document.createElementNS(ns, 'svg');
					svg.setAttribute('viewBox', '0 0 24 24');
					svg.setAttribute('width', '18');
					svg.setAttribute('height', '18');
					svg.setAttribute('fill', 'currentColor');
					var c1 = document.createElementNS(ns, 'circle');
					c1.setAttribute('cx', '6'); c1.setAttribute('cy', '12'); c1.setAttribute('r', '2');
					var c2 = document.createElementNS(ns, 'circle');
					c2.setAttribute('cx', '12'); c2.setAttribute('cy', '12'); c2.setAttribute('r', '2');
					var c3 = document.createElementNS(ns, 'circle');
					c3.setAttribute('cx', '18'); c3.setAttribute('cy', '12'); c3.setAttribute('r', '2');
					svg.appendChild(c1);
					svg.appendChild(c2);
					svg.appendChild(c3);
					return svg;
				}

				// 1. Highlight-Button
				var highlightBtn = document.createElement('button');
				highlightBtn.className = 'ann-toolbar-btn';
				highlightBtn.title = __('Hervorheben');
				highlightBtn.appendChild(createHighlightIcon());
				highlightBtn.addEventListener('click', function () {
					var selectorPath = JSON.stringify({before: beforeText});
					Plugins.Annotations.save(articleId, selectedText, '', '#fff3cd',
						range.startOffset, range.endOffset, selectorPath);
					Plugins.Annotations.removeContextToolbar();
					window.getSelection().removeAllRanges();
				});

				// 2. Notiz-Button
				var noteBtn = document.createElement('button');
				noteBtn.className = 'ann-toolbar-btn';
				noteBtn.title = __('Notiz hinzufügen');
				noteBtn.appendChild(createNoteIcon());
				noteBtn.addEventListener('click', function () {
					Plugins.Annotations.removeContextToolbar();
					Plugins.Annotations.showPopup(sel, wrapper);
				});

				// 3. Tag-Button
				var tagBtn = document.createElement('button');
				tagBtn.className = 'ann-toolbar-btn';
				tagBtn.title = __('Tag hinzufügen');
				tagBtn.appendChild(createTagIcon());
				tagBtn.addEventListener('click', function () {
					Plugins.Annotations.removeContextToolbar();
					Plugins.Annotations._showTagInput(sel, wrapper, articleId);
				});

				// 4. Mehr-Button (Platzhalter)
				var moreBtn = document.createElement('button');
				moreBtn.className = 'ann-toolbar-btn ann-toolbar-btn-more';
				moreBtn.title = __('Weitere Optionen');
				moreBtn.appendChild(createMoreIcon());

				toolbar.appendChild(highlightBtn);
				toolbar.appendChild(noteBtn);
				toolbar.appendChild(tagBtn);
				toolbar.appendChild(moreBtn);
				document.body.appendChild(toolbar);

				// Schließen bei Klick außerhalb
				setTimeout(function () {
					document.addEventListener('mousedown', function handler(e) {
						if (!e.target.closest('.ann-context-toolbar') && !e.target.closest('.ann-popup')) {
							Plugins.Annotations.removeContextToolbar();
							document.removeEventListener('mousedown', handler, true);
						}
					}, true);
				}, 100);
			},

			removeContextToolbar: function () {
				document.querySelectorAll('.ann-context-toolbar').forEach(function (el) {
					el.remove();
				});
			},

			/**
			 * Tag-Eingabe: öffnet ein kleines Inline-Feld über der Auswahl.
			 */
			_showTagInput: function (sel, wrapper, articleId) {
				Plugins.Annotations.removePopup();

				var range = sel.getRangeAt(0);
				var rect = range.getBoundingClientRect();

				var popup = document.createElement('div');
				popup.className = 'ann-popup ann-tag-popup';
				popup.style.position = 'fixed';
				popup.style.left = Math.min(rect.left, window.innerWidth - 280) + 'px';
				popup.style.top = (rect.top - 70) + 'px';
				popup.style.zIndex = '10001';

				if (rect.top - 70 < 4) {
					popup.style.top = (rect.bottom + 8) + 'px';
				}

				var label = document.createElement('div');
				label.className = 'ann-tag-label';
				label.textContent = __('Tag hinzufügen');
				popup.appendChild(label);

				var inputRow = document.createElement('div');
				inputRow.className = 'ann-tag-input-row';

				var input = document.createElement('input');
				input.type = 'text';
				input.className = 'ann-tag-input';
				input.placeholder = __('Tag eingeben...');
				input.addEventListener('keydown', function (e) {
					if (e.key === 'Enter' && input.value.trim()) {
						doAddTag();
					} else if (e.key === 'Escape') {
						Plugins.Annotations.removePopup();
					}
				});

				var addBtn = document.createElement('button');
				addBtn.className = 'ann-save-btn';
				addBtn.textContent = __('Hinzufügen');
				addBtn.addEventListener('click', function () {
					if (input.value.trim()) doAddTag();
				});

				inputRow.appendChild(input);
				inputRow.appendChild(addBtn);
				popup.appendChild(inputRow);
				document.body.appendChild(popup);

				setTimeout(function () { input.focus(); }, 50);

				function doAddTag() {
					var tag = input.value.trim().toLowerCase();
					if (!tag) return;

					xhr.json("backend.php", App.getPhArgs("annotations", "add_article_tag", {
						id: articleId,
						tag: tag
					}), function (reply) {
						if (reply && reply.status === 'ok') {
							Notify.info(__('Tag hinzugefügt: ') + reply.tag);
						}
					});

					Plugins.Annotations.removePopup();
					window.getSelection().removeAllRanges();
				}

				setTimeout(function () {
					document.addEventListener('mousedown', function handler(e) {
						if (!popup.contains(e.target)) {
							Plugins.Annotations.removePopup();
							document.removeEventListener('mousedown', handler, true);
						}
					}, true);
				}, 200);
			},

			showPopup: function (sel, wrapper) {
				Plugins.Annotations.removePopup();

				var range = sel.getRangeAt(0);
				var rect = range.getBoundingClientRect();
				var selectedText = sel.toString().trim();

				if (!selectedText) return;

				var articleId = wrapper.getAttribute('data-article-id');

				// Kontext für spätere Zuordnung erfassen
				var beforeText = '';
				try {
					var startNode = range.startContainer;
					beforeText = startNode.textContent.substring(
						Math.max(0, range.startOffset - 20), range.startOffset);
				} catch (e) { /* ignorieren */ }

				var popup = document.createElement('div');
				popup.className = 'ann-popup';
				popup.style.position = 'fixed';
				popup.style.left = Math.min(rect.left, window.innerWidth - 300) + 'px';
				popup.style.top = (rect.bottom + 5) + 'px';
				popup.style.zIndex = '10000';

				var preview = document.createElement('div');
				preview.className = 'ann-popup-preview';
				preview.textContent = selectedText.length > 80
					? selectedText.substring(0, 80) + '\u2026'
					: selectedText;
				popup.appendChild(preview);

				var colorRow = document.createElement('div');
				colorRow.className = 'ann-color-row';

				Plugins.Annotations.colors.forEach(function (color) {
					var btn = document.createElement('span');
					btn.className = 'ann-color-btn';
					btn.style.backgroundColor = color;
					btn.dataset.color = color;
					btn.addEventListener('click', function () {
						popup.querySelectorAll('.ann-color-btn').forEach(function (b) {
							b.classList.remove('ann-color-active');
						});
						btn.classList.add('ann-color-active');
					});
					colorRow.appendChild(btn);
				});

				if (colorRow.firstChild) {
					colorRow.firstChild.classList.add('ann-color-active');
				}

				var noteInput = document.createElement('textarea');
				noteInput.className = 'ann-note-input';
				noteInput.placeholder = __('Notiz hinzufügen (optional)...');
				noteInput.rows = 2;

				var btnRow = document.createElement('div');
				btnRow.className = 'ann-btn-row';

				var saveBtn = document.createElement('button');
				saveBtn.className = 'ann-save-btn';
				saveBtn.textContent = __('Speichern');
				saveBtn.addEventListener('click', function () {
					var activeColor = popup.querySelector('.ann-color-active');
					var color = activeColor ? activeColor.dataset.color : '#fff3cd';
					var note = noteInput.value;

					// Kontext als JSON im selector_path speichern
					var selectorPath = JSON.stringify({before: beforeText});

					Plugins.Annotations.save(articleId, selectedText, note, color,
						range.startOffset, range.endOffset, selectorPath);

					Plugins.Annotations.removePopup();
					window.getSelection().removeAllRanges();
				});

				var cancelBtn = document.createElement('button');
				cancelBtn.className = 'ann-cancel-btn';
				cancelBtn.textContent = __('Abbrechen');
				cancelBtn.addEventListener('click', function () {
					Plugins.Annotations.removePopup();
				});

				btnRow.appendChild(saveBtn);
				btnRow.appendChild(cancelBtn);

				popup.appendChild(colorRow);
				popup.appendChild(noteInput);
				popup.appendChild(btnRow);
				document.body.appendChild(popup);

				setTimeout(function () {
					document.addEventListener('mousedown', function handler(e) {
						if (!popup.contains(e.target)) {
							Plugins.Annotations.removePopup();
							document.removeEventListener('mousedown', handler, true);
						}
					}, true);
				}, 200);
			},

			removePopup: function () {
				document.querySelectorAll('.ann-popup').forEach(function (el) {
					el.remove();
				});
			},

			save: function (articleId, text, note, color, startOffset, endOffset, selectorPath) {
				xhr.json("backend.php", App.getPhArgs("annotations", "save_annotation", {
					ref_id: articleId,
					highlighted_text: text,
					note: note,
					color: color,
					start_offset: startOffset,
					end_offset: endOffset,
					selector_path: selectorPath
				}), function (reply) {
					if (reply && reply.status === 'ok') {
						Notify.info(__('Annotation gespeichert'));
						var wrapper = document.querySelector(
							'.annotations-wrapper[data-article-id="' + articleId + '"]');
						if (wrapper) {
							var newAnn = {
								id: reply.id,
								highlighted_text: text,
								note: note,
								color: color,
								selector_path: selectorPath
							};
							Plugins.Annotations.applyHighlights(wrapper, [newAnn]);

							try {
								var existing = JSON.parse(wrapper.getAttribute('data-annotations') || '[]');
								existing.push(newAnn);
								wrapper.setAttribute('data-annotations', JSON.stringify(existing));
							} catch (e) { /* ignorieren */ }
						}
					}
				});
			},

			showPanel: function (articleId) {
				xhr.json("backend.php", App.getPhArgs("annotations", "get_annotations", {
					article_id: articleId
				}), function (reply) {
					if (!reply) return;

					// HTML-String bauen damit Dojo-Parser + inline onclick funktionieren
					var html = '<h3>' + __('Annotationen') + '</h3>';

					if (reply.length === 0) {
						html += '<p>' + __('Keine Annotationen vorhanden. Markiere Text im Artikel, um eine Annotation zu erstellen.') + '</p>';
					} else {
						html += '<table class="ann-panel-table" width="100%">';
						html += '<tr class="title"><td>' + __('Text') + '</td><td>' +
							__('Notiz') + '</td><td></td></tr>';

						reply.forEach(function (ann) {
							var textPreview = App.escapeHtml(
								ann.highlighted_text.length > 80
									? ann.highlighted_text.substring(0, 80) + '\u2026'
									: ann.highlighted_text);
							var notePreview = App.escapeHtml(ann.note || '\u2014');
							var escapedText = App.escapeHtml(ann.highlighted_text).replace(/'/g, "\\'").replace(/"/g, '&quot;');
							var escapedNote = App.escapeHtml(ann.note || '').replace(/'/g, "\\'").replace(/"/g, '&quot;');

							html += '<tr>';
							html += '<td><span class="ann-color-dot" style="background:' +
								App.escapeHtml(ann.color || '#fff3cd') + '"></span>' + textPreview + '</td>';
							html += '<td>' + notePreview + '</td>';
							html += '<td class="ann-actions-cell">';
							html += '<i class="material-icons ann-action-icon" style="cursor:pointer" title="' + __('Kopieren') + '" ' +
								'onclick="Plugins.Annotations._copyToClipboard(\'' + escapedText + '\', \'' + escapedNote + '\')">content_copy</i>';
							html += '<i class="material-icons ann-action-icon ann-delete-icon" style="cursor:pointer" title="' + __('Löschen') + '" ' +
								'onclick="Plugins.Annotations.deleteAnn(' + ann.id + ',' + articleId + ')">delete</i>';
							html += '</td></tr>';
						});
						html += '</table>';
					}

					html += '<footer class="text-center" style="margin-top:12px">' +
						'<button class="alt-primary" dojoType="dijit.form.Button" ' +
						'onclick="App.dialogOf(this).hide()">' + __('Schließen') + '</button></footer>';

					var dialog = new fox.SingleUseDialog({
						title: __('Annotationen'),
						content: html
					});
					dialog.show();
				});
			},

			_copyToClipboard: function (text, note) {
				var copyText = text + (note ? '\n\nNotiz: ' + note : '');
				if (navigator.clipboard) {
					navigator.clipboard.writeText(copyText).then(function () {
						Notify.info(__('Kopiert'));
					});
				}
			},

			deleteAnn: function (annId, articleId) {
				xhr.json("backend.php", App.getPhArgs("annotations", "delete_annotation", {
					id: annId
				}), function (reply) {
					if (reply && reply.status === 'ok') {
						Notify.info(__('Annotation gelöscht'));
						document.querySelectorAll('mark[data-ann-id="' + annId + '"]').forEach(function (el) {
							var parent = el.parentNode;
							while (el.firstChild) {
								parent.insertBefore(el.firstChild, el);
							}
							parent.removeChild(el);
						});

						var wrapper = document.querySelector(
							'.annotations-wrapper[data-article-id="' + articleId + '"]');
						if (wrapper) {
							try {
								var existing = JSON.parse(wrapper.getAttribute('data-annotations') || '[]');
								existing = existing.filter(function (a) { return a.id != annId; });
								wrapper.setAttribute('data-annotations', JSON.stringify(existing));
							} catch (e) { /* ignorieren */ }
						}
					}
				});
			}
		};

		PluginHost.register(PluginHost.HOOK_HEADLINES_RENDERED, function () {
			Plugins.Annotations.applyAll();
		});

		PluginHost.register(PluginHost.HOOK_ARTICLE_RENDERED_CDM, function () {
			Plugins.Annotations.applyAll();
		});

		PluginHost.register(PluginHost.HOOK_ARTICLE_RENDERED, function () {
			Plugins.Annotations.applyAll();
		});

		Plugins.Annotations.init();
	});
});
