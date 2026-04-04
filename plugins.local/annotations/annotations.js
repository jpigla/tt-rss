/* global require, Plugins, PluginHost, xhr, App, Notify, fox, __ */

require(['dojo/_base/kernel', 'dojo/ready'], function (dojo, ready) {
	ready(function () {

		Plugins.Annotations = {

			colors: ['#fff3cd', '#d4edda', '#cce5ff', '#f8d7da', '#e2d5f1', '#ffeeba'],

			init: function () {
				document.addEventListener('mouseup', function (e) {
					const wrapper = e.target.closest('.annotations-wrapper');
					if (!wrapper) return;

					const sel = window.getSelection();
					if (!sel || sel.isCollapsed || !sel.toString().trim()) return;

					Plugins.Annotations.showPopup(sel, wrapper);
				});

				Plugins.Annotations.applyAll();
			},

			applyAll: function () {
				document.querySelectorAll('.annotations-wrapper[data-annotations]').forEach(function (el) {
					try {
						const annotations = JSON.parse(el.getAttribute('data-annotations'));
						if (annotations && annotations.length > 0) {
							Plugins.Annotations.applyHighlights(el, annotations);
						}
					} catch (e) {
						// JSON-Parse-Fehler ignorieren
					}
				});
			},

			applyHighlights: function (wrapper, annotations) {
				annotations.forEach(function (ann) {
					const mark = document.createElement('mark');
					mark.className = 'ann-highlight';
					mark.style.backgroundColor = ann.color || '#fff3cd';
					mark.dataset.annId = ann.id;
					if (ann.note) {
						mark.title = ann.note;
						mark.classList.add('ann-has-note');
					}

					// Versuche anhand des gespeicherten Textes zu finden
					const textContent = ann.highlighted_text;
					if (!textContent) return;

					const treeWalker = document.createTreeWalker(
						wrapper, NodeFilter.SHOW_TEXT, null, false
					);

					let node;
					while ((node = treeWalker.nextNode())) {
						const idx = node.textContent.indexOf(textContent);
						if (idx !== -1) {
							const range = document.createRange();
							range.setStart(node, idx);
							range.setEnd(node, Math.min(idx + textContent.length, node.textContent.length));
							range.surroundContents(mark);
							break;
						}
					}
				});
			},

			showPopup: function (sel, wrapper) {
				Plugins.Annotations.removePopup();

				const range = sel.getRangeAt(0);
				const rect = range.getBoundingClientRect();
				const selectedText = sel.toString().trim();

				if (!selectedText) return;

				const articleId = wrapper.getAttribute('data-article-id');

				const popup = document.createElement('div');
				popup.className = 'ann-popup';
				popup.style.position = 'fixed';
				popup.style.left = rect.left + 'px';
				popup.style.top = (rect.bottom + 5) + 'px';
				popup.style.zIndex = '10000';

				const colorRow = document.createElement('div');
				colorRow.className = 'ann-color-row';

				Plugins.Annotations.colors.forEach(function (color) {
					const btn = document.createElement('span');
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

				// Standard: erste Farbe
				if (colorRow.firstChild) {
					colorRow.firstChild.classList.add('ann-color-active');
				}

				const noteInput = document.createElement('textarea');
				noteInput.className = 'ann-note-input';
				noteInput.placeholder = __('Notiz hinzufügen...');
				noteInput.rows = 2;

				const btnRow = document.createElement('div');
				btnRow.className = 'ann-btn-row';

				const saveBtn = document.createElement('button');
				saveBtn.className = 'ann-save-btn';
				saveBtn.textContent = __('Speichern');
				saveBtn.addEventListener('click', function () {
					const activeColor = popup.querySelector('.ann-color-active');
					const color = activeColor ? activeColor.dataset.color : '#fff3cd';
					const note = noteInput.value;

					Plugins.Annotations.save(articleId, selectedText, note, color,
						range.startOffset, range.endOffset, '');

					Plugins.Annotations.removePopup();
				});

				const cancelBtn = document.createElement('button');
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
						// Hervorhebung sofort anwenden
						const wrapper = document.querySelector(
							'.annotations-wrapper[data-article-id="' + articleId + '"]');
						if (wrapper) {
							Plugins.Annotations.applyHighlights(wrapper, [{
								id: reply.id,
								highlighted_text: text,
								note: note,
								color: color
							}]);
						}
					}
				});
			},

			showPanel: function (articleId) {
				xhr.json("backend.php", App.getPhArgs("annotations", "get_annotations", {
					article_id: articleId
				}), function (reply) {
					if (!reply) return;

					let content = '<h3>' + __('Annotationen') + '</h3>';
					if (reply.length === 0) {
						content += '<p>' + __('Keine Annotationen vorhanden. Markiere Text im Artikel, um eine Annotation zu erstellen.') + '</p>';
					} else {
						content += '<table width="100%"><tr class="title"><td>' + __('Text') + '</td><td>' +
							__('Notiz') + '</td><td></td></tr>';
						reply.forEach(function (ann) {
							content += '<tr><td><mark style="background:' + ann.color + '">' +
								App.escapeHtml(ann.highlighted_text.substring(0, 60)) + '</mark></td>' +
								'<td>' + App.escapeHtml(ann.note || '') + '</td>' +
								'<td><i class="material-icons" style="cursor:pointer" ' +
								'onclick="Plugins.Annotations.deleteAnn(' + ann.id + ',' + articleId + ')">' +
								'delete</i></td></tr>';
						});
						content += '</table>';
					}

					content += '<footer class="text-center">' +
						'<button class="alt-primary" dojoType="dijit.form.Button" ' +
						'onclick="App.dialogOf(this).hide()">' + __('Schließen') + '</button></footer>';

					const dialog = new fox.SingleUseDialog({
						title: __('Annotationen'),
						content: content
					});
					dialog.show();
				});
			},

			deleteAnn: function (annId, articleId) {
				xhr.json("backend.php", App.getPhArgs("annotations", "delete_annotation", {
					id: annId
				}), function (reply) {
					if (reply && reply.status === 'ok') {
						Notify.info(__('Annotation gelöscht'));
						// Markierung entfernen
						document.querySelectorAll('mark[data-ann-id="' + annId + '"]').forEach(function (el) {
							const parent = el.parentNode;
							while (el.firstChild) {
								parent.insertBefore(el.firstChild, el);
							}
							parent.removeChild(el);
						});
					}
				});
			},

			deleteFromPrefs: function (annId) {
				if (!confirm(__('Annotation wirklich löschen?'))) return;

				xhr.json("backend.php", App.getPhArgs("annotations", "delete_annotation", {
					id: annId
				}), function (reply) {
					if (reply && reply.status === 'ok') {
						Notify.info(__('Annotation gelöscht'));
						window.location.reload();
					}
				});
			}
		};

		PluginHost.register(PluginHost.HOOK_HEADLINES_RENDERED, function () {
			Plugins.Annotations.applyAll();
		});

		Plugins.Annotations.init();
	});
});
