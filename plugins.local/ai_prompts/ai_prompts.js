/* global Plugins, Notify, xhr, fox */

Plugins.Ai_Prompts = {
	showMenu: function(id) {
		Notify.progress('Lade Prompts...');

		xhr.json("backend.php", {
			op: "pluginhandler", plugin: "ai_prompts", method: "get_prompt_list"
		}, (prompts) => {
			Notify.close();

			if (!prompts || prompts.length === 0) {
				Notify.error('Keine Prompts konfiguriert. Bitte in den Einstellungen anlegen.');
				return;
			}

			// Dropdown-Menü erstellen
			const existing = document.getElementById('ai-prompts-dropdown');
			if (existing) existing.remove();

			const menu = document.createElement('div');
			menu.id = 'ai-prompts-dropdown';
			menu.className = 'ai-prompts-dropdown';

			const header = document.createElement('div');
			header.className = 'ai-prompts-dropdown-header';
			header.textContent = 'KI-Prompt auswählen';
			menu.appendChild(header);

			prompts.forEach(function(p) {
				const item = document.createElement('div');
				item.className = 'ai-prompts-dropdown-item';
				item.textContent = p.name;
				item.title = p.template;
				item.onclick = function() {
					menu.remove();
					Plugins.Ai_Prompts._execute(id, p.id, p.name);
				};
				menu.appendChild(item);
			});

			// Position neben dem Button
			const btn = document.querySelector('.ai-prompts-btn[data-article-id="' + id + '"]');
			if (btn) {
				const rect = btn.getBoundingClientRect();
				menu.style.position = 'fixed';
				menu.style.left = rect.left + 'px';
				menu.style.top = (rect.bottom + 4) + 'px';
				menu.style.zIndex = '10000';
			}

			document.body.appendChild(menu);

			// Schließen bei Klick außerhalb
			const closeHandler = function(e) {
				if (!menu.contains(e.target)) {
					menu.remove();
					document.removeEventListener('click', closeHandler, true);
				}
			};
			setTimeout(function() {
				document.addEventListener('click', closeHandler, true);
			}, 100);
		});
	},

	_execute: function(articleId, promptId, promptName) {
		Notify.progress('KI-Prompt wird ausgeführt...');

		xhr.json("backend.php", {
			op: "pluginhandler", plugin: "ai_prompts", method: "execute",
			article_id: articleId, prompt_id: promptId
		}, (reply) => {
			if (reply.error) {
				Notify.error(reply.error);
				return;
			}

			Notify.close();
			Plugins.Ai_Prompts._showResult(articleId, reply.prompt_name, reply.result);
		});
	},

	_showResult: function(articleId, promptName, result) {
		const dlg = document.createElement('div');
		dlg.className = 'ai-prompts-dialog';

		const overlay = document.createElement('div');
		overlay.className = 'ai-prompts-overlay';
		overlay.onclick = function() {
			overlay.remove();
			dlg.remove();
		};

		const header = document.createElement('div');
		header.className = 'ai-prompts-dialog-header';

		const title = document.createElement('span');
		title.textContent = promptName;
		header.appendChild(title);

		const closeBtn = document.createElement('i');
		closeBtn.className = 'material-icons';
		closeBtn.textContent = 'close';
		closeBtn.style.cursor = 'pointer';
		closeBtn.onclick = function() {
			overlay.remove();
			dlg.remove();
		};
		header.appendChild(closeBtn);

		dlg.appendChild(header);

		const body = document.createElement('div');
		body.className = 'ai-prompts-dialog-body';
		body.textContent = result;
		dlg.appendChild(body);

		document.body.appendChild(overlay);
		document.body.appendChild(dlg);
	},

	editPrompt: function(promptId) {
		// Wird über Prefs-Tab aufgerufen, einfaches Reload
		Notify.info('Bitte Prompt direkt im Formular bearbeiten.');
	},

	deletePrompt: function(promptId) {
		if (!confirm('Prompt wirklich löschen?')) return;

		Notify.progress('Lösche Prompt...');
		xhr.post("backend.php", {
			op: "pluginhandler", plugin: "ai_prompts", method: "delete_prompt",
			prompt_id: promptId
		}, () => {
			Notify.info('Prompt gelöscht');
			window.location.reload();
		});
	}
};
