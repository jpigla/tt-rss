/* global Plugins, Notify, xhr, Feeds */

Plugins.Enhanced_Tags = {
	_all_tags_cache: null,

	edit: function(id) {
		// Aktuelle Tags und alle Tags parallel laden
		const loadTags = new Promise((resolve) => {
			xhr.json("backend.php", {
				op: "pluginhandler", plugin: "enhanced_tags", method: "get_article_tags", id: id
			}, resolve);
		});

		const loadAllTags = this._all_tags_cache
			? Promise.resolve(this._all_tags_cache)
			: new Promise((resolve) => {
				xhr.json("backend.php", {
					op: "pluginhandler", plugin: "enhanced_tags", method: "get_all_tags"
				}, (tags) => {
					Plugins.Enhanced_Tags._all_tags_cache = tags;
					resolve(tags);
				});
			});

		Promise.all([loadTags, loadAllTags]).then(([current_tags, all_tags]) => {
			const dialog = new dijit.Dialog({
				title: "Tags bearbeiten",
				style: "width: 450px",
				content: Plugins.Enhanced_Tags._buildEditForm(id, current_tags, all_tags)
			});
			dialog.show();
		});
	},

	_buildEditForm: function(id, current_tags, all_tags) {
		const container = document.createElement('div');

		// Tag-Input
		const input = document.createElement('input');
		input.type = 'text';
		input.id = 'et-tag-input';
		input.value = current_tags.join(', ');
		input.style.cssText = 'width: 100%; padding: 6px; font-size: 13px; margin-bottom: 8px;';
		input.placeholder = 'tag1, tag2, tag3';
		container.appendChild(input);

		// Vorschläge (nur statische Textinhalte, kein HTML-Injection-Risiko)
		if (all_tags && all_tags.length > 0) {
			const suggestions = document.createElement('div');
			suggestions.className = 'et-suggestions';
			suggestions.style.cssText = 'margin-bottom: 12px; max-height: 120px; overflow-y: auto;';

			const top_tags = all_tags.slice(0, 30);
			top_tags.forEach((tag) => {
				const chip = document.createElement('span');
				chip.className = 'et-suggest-chip';
				chip.textContent = '#' + tag.name + ' (' + tag.count + ')';
				chip.style.cssText = 'display: inline-block; padding: 2px 8px; margin: 2px; border-radius: 12px; ' +
					'font-size: 11px; cursor: pointer; background: var(--bg-secondary, #f0f0f0);';
				chip.onclick = function() {
					const current = input.value.split(',').map(t => t.trim()).filter(t => t);
					if (!current.includes(tag.name)) {
						current.push(tag.name);
						input.value = current.join(', ');
					}
				};
				suggestions.appendChild(chip);
			});
			container.appendChild(suggestions);
		}

		// Buttons -- nur statische Inhalte, kein User-Input in DOM
		const footer = document.createElement('div');
		footer.style.cssText = 'text-align: center; padding-top: 8px;';

		const saveBtn = document.createElement('button');
		saveBtn.textContent = 'Speichern';
		saveBtn.style.cssText = 'padding: 4px 16px; margin-right: 8px;';
		saveBtn.onclick = function() { Plugins.Enhanced_Tags._save(id); };
		footer.appendChild(saveBtn);

		const cancelBtn = document.createElement('button');
		cancelBtn.textContent = 'Abbrechen';
		cancelBtn.style.cssText = 'padding: 4px 16px;';
		cancelBtn.onclick = function() {
			const widget = dijit.byId(this.closest('[widgetid]').getAttribute('widgetid'));
			if (widget) widget.hide();
		};
		footer.appendChild(cancelBtn);

		container.appendChild(footer);

		return container;
	},

	_save: function(id) {
		const input = document.getElementById('et-tag-input');
		if (!input) return;

		const tags = input.value;

		xhr.json("backend.php", {
			op: "pluginhandler", plugin: "enhanced_tags", method: "save_tags", id: id, tags: tags
		}, (reply) => {
			if (reply && reply.tags) {
				// Tag-Cache invalidieren
				Plugins.Enhanced_Tags._all_tags_cache = null;

				// Button aktualisieren
				const btn = document.querySelector(`.et-tag-btn[data-article-id="${id}"]`);
				if (btn) {
					if (reply.tags.length > 0) {
						btn.classList.add('et-has-tags');
					} else {
						btn.classList.remove('et-has-tags');
					}
				}

				Notify.info('Tags gespeichert');

				// Dialog schließen
				const widget = dijit.byId(input.closest('[widgetid]').getAttribute('widgetid'));
				if (widget) widget.hide();
			}
		});
	},

	search: function(tag) {
		Feeds.search(tag);
	}
};
