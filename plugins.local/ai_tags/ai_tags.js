/* global Plugins, Notify, xhr */

Plugins.Ai_Tags = {
	suggest: function(id) {
		var btn = document.querySelector('.ai-tags-btn[data-article-id="' + id + '"]');
		if (btn) btn.style.animation = 'ai-tags-pulse 1.2s ease-in-out infinite';

		Notify.progress('Tags werden vorgeschlagen...');

		xhr.json("backend.php", {
			op: "pluginhandler", plugin: "ai_tags", method: "suggest",
			article_id: id
		}, function(reply) {
			if (btn) btn.style.animation = '';

			if (reply.error) {
				Notify.error(reply.error);
				return;
			}

			Notify.close();
			Plugins.Ai_Tags._showSuggestions(id, reply.tags);
		});
	},

	_showSuggestions: function(articleId, tags) {
		var existing = document.getElementById('ai-tags-overlay');
		if (existing) existing.remove();
		existing = document.getElementById('ai-tags-dialog');
		if (existing) existing.remove();

		// Overlay
		var overlay = document.createElement('div');
		overlay.id = 'ai-tags-overlay';
		overlay.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.4);z-index:9999';
		overlay.onclick = function() {
			overlay.remove();
			document.getElementById('ai-tags-dialog').remove();
		};

		// Dialog
		var dlg = document.createElement('div');
		dlg.id = 'ai-tags-dialog';
		dlg.style.cssText = 'position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:var(--bg-primary,#fff);border-radius:8px;box-shadow:0 8px 32px rgba(0,0,0,0.2);width:400px;max-width:90vw;z-index:10000;padding:16px';

		// Header
		var header = document.createElement('div');
		header.style.cssText = 'display:flex;align-items:center;gap:8px;margin-bottom:12px;font-weight:600';

		var icon = document.createElement('i');
		icon.className = 'material-icons';
		icon.textContent = 'auto_fix_high';
		icon.style.color = 'var(--color-accent, #4caf50)';
		header.appendChild(icon);

		var title = document.createElement('span');
		title.textContent = 'Vorgeschlagene Tags';
		header.appendChild(title);

		var closeBtn = document.createElement('i');
		closeBtn.className = 'material-icons';
		closeBtn.textContent = 'close';
		closeBtn.style.cssText = 'cursor:pointer;margin-left:auto';
		closeBtn.onclick = function() {
			overlay.remove();
			dlg.remove();
		};
		header.appendChild(closeBtn);

		dlg.appendChild(header);

		// Tag-Checkboxen
		var checkboxes = [];
		var tagContainer = document.createElement('div');
		tagContainer.style.cssText = 'display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px';

		tags.forEach(function(tag) {
			var label = document.createElement('label');
			label.style.cssText = 'display:flex;align-items:center;gap:4px;padding:4px 10px;border:1px solid var(--border-color,#ddd);border-radius:16px;cursor:pointer;font-size:13px';

			var cb = document.createElement('input');
			cb.type = 'checkbox';
			cb.checked = true;
			cb.value = tag;
			checkboxes.push(cb);
			label.appendChild(cb);

			var span = document.createElement('span');
			span.textContent = '#' + tag;
			label.appendChild(span);

			tagContainer.appendChild(label);
		});

		dlg.appendChild(tagContainer);

		// Buttons
		var btnArea = document.createElement('div');
		btnArea.style.cssText = 'display:flex;gap:8px;justify-content:flex-end';

		var applyBtn = document.createElement('button');
		applyBtn.style.cssText = 'padding:6px 16px;border:none;border-radius:4px;background:var(--color-accent,#4caf50);color:#fff;cursor:pointer;font-size:13px';
		applyBtn.textContent = 'Alle übernehmen';
		applyBtn.onclick = function() {
			var selected = [];
			checkboxes.forEach(function(cb) {
				if (cb.checked) selected.push(cb.value);
			});

			if (selected.length === 0) {
				Notify.error('Keine Tags ausgewählt.');
				return;
			}

			Notify.progress('Tags werden gespeichert...');

			xhr.json("backend.php", {
				op: "pluginhandler", plugin: "ai_tags", method: "apply_tags",
				article_id: articleId, tags: selected.join(',')
			}, function(reply) {
				overlay.remove();
				dlg.remove();

				if (reply.error) {
					Notify.error(reply.error);
				} else {
					Notify.info(reply.added.length + ' Tags hinzugefügt');
				}
			});
		};
		btnArea.appendChild(applyBtn);

		var cancelBtn = document.createElement('button');
		cancelBtn.style.cssText = 'padding:6px 16px;border:1px solid var(--border-color,#ddd);border-radius:4px;background:transparent;cursor:pointer;font-size:13px;color:var(--fg-primary,#333)';
		cancelBtn.textContent = 'Abbrechen';
		cancelBtn.onclick = function() {
			overlay.remove();
			dlg.remove();
		};
		btnArea.appendChild(cancelBtn);

		dlg.appendChild(btnArea);

		document.body.appendChild(overlay);
		document.body.appendChild(dlg);
	}
};
