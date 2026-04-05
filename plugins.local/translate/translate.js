/* global Plugins, Notify, xhr */

Plugins.Translate = {
	_languages: {
		de: 'Deutsch', en: 'Englisch', fr: 'Französisch',
		es: 'Spanisch', it: 'Italienisch', pt: 'Portugiesisch',
		ja: 'Japanisch', zh: 'Chinesisch', ru: 'Russisch', ar: 'Arabisch'
	},

	_originals: {},

	showMenu: function(id) {
		var existing = document.getElementById('translate-dropdown');
		if (existing) existing.remove();

		var menu = document.createElement('div');
		menu.id = 'translate-dropdown';
		menu.className = 'translate-dropdown';

		var header = document.createElement('div');
		header.className = 'translate-dropdown-header';
		header.textContent = 'Sprache wählen';
		menu.appendChild(header);

		var langs = Plugins.Translate._languages;
		Object.keys(langs).forEach(function(code) {
			var item = document.createElement('div');
			item.className = 'translate-dropdown-item';
			item.textContent = langs[code];
			item.onclick = function() {
				menu.remove();
				Plugins.Translate._translate(id, code);
			};
			menu.appendChild(item);
		});

		var btn = document.querySelector('.translate-btn[data-article-id="' + id + '"]');
		if (btn) {
			var rect = btn.getBoundingClientRect();
			menu.style.position = 'fixed';
			menu.style.left = rect.left + 'px';
			menu.style.top = (rect.bottom + 4) + 'px';
			menu.style.zIndex = '10000';
		}

		document.body.appendChild(menu);

		var closeHandler = function(e) {
			if (!menu.contains(e.target)) {
				menu.remove();
				document.removeEventListener('click', closeHandler, true);
			}
		};
		setTimeout(function() {
			document.addEventListener('click', closeHandler, true);
		}, 100);
	},

	_translate: function(articleId, targetLang) {
		var btn = document.querySelector('.translate-btn[data-article-id="' + articleId + '"]');
		if (btn) btn.classList.add('translate-loading');

		Notify.progress('Übersetze Artikel...');

		xhr.json("backend.php", {
			op: "pluginhandler", plugin: "translate", method: "translate",
			article_id: articleId, target_lang: targetLang
		}, function(reply) {
			if (btn) btn.classList.remove('translate-loading');

			if (reply.error) {
				Notify.error(reply.error);
				return;
			}

			Notify.info(reply.cached ? 'Übersetzung aus Cache' : 'Übersetzung abgeschlossen');
			Plugins.Translate._showTranslation(articleId, reply);
		});
	},

	_showTranslation: function(articleId, data) {
		var contentEl = document.querySelector(
			'#RROW-' + articleId + ' .content-inner'
		) || document.querySelector('#content-insert .post .content');

		if (!contentEl) return;

		// Originalen Inhalt sichern (DOM-Klon)
		if (!Plugins.Translate._originals[articleId]) {
			var clone = contentEl.cloneNode(true);
			Plugins.Translate._originals[articleId] = clone;
		}

		// Vorhandene Toggle/Übersetzung entfernen
		var existingToggle = document.getElementById('translate-toggle-' + articleId);
		if (existingToggle) existingToggle.remove();
		var existingTranslation = document.getElementById('translate-content-' + articleId);
		if (existingTranslation) existingTranslation.remove();

		// Toggle-Button erstellen
		var toggle = document.createElement('div');
		toggle.id = 'translate-toggle-' + articleId;
		toggle.className = 'translate-toggle';

		var label = document.createElement('span');
		label.className = 'translate-toggle-label';
		label.textContent = data.cached ? 'Übersetzt (Cache)' : 'Übersetzt';
		toggle.appendChild(label);

		var toggleBtn = document.createElement('button');
		toggleBtn.className = 'translate-toggle-btn';
		toggleBtn.textContent = 'Original anzeigen';
		toggleBtn.dataset.showing = 'translated';
		toggleBtn.onclick = function() {
			var translationDiv = document.getElementById('translate-content-' + articleId);
			if (toggleBtn.dataset.showing === 'translated') {
				if (translationDiv) translationDiv.style.display = 'none';
				// Original-Kinder wieder sichtbar machen
				Plugins.Translate._setOriginalChildrenVisible(contentEl, true);
				toggleBtn.textContent = 'Übersetzung anzeigen';
				toggleBtn.dataset.showing = 'original';
			} else {
				if (translationDiv) translationDiv.style.display = '';
				Plugins.Translate._setOriginalChildrenVisible(contentEl, false);
				toggleBtn.textContent = 'Original anzeigen';
				toggleBtn.dataset.showing = 'translated';
			}
		};
		toggle.appendChild(toggleBtn);

		contentEl.insertBefore(toggle, contentEl.firstChild);

		// Übersetzungsinhalt einfügen
		var translationDiv = document.createElement('div');
		translationDiv.id = 'translate-content-' + articleId;
		translationDiv.className = 'translate-content';

		var titleEl = document.createElement('h3');
		titleEl.textContent = data.title;
		translationDiv.appendChild(titleEl);

		var bodyEl = document.createElement('div');
		bodyEl.style.whiteSpace = 'pre-wrap';
		bodyEl.textContent = data.content;
		translationDiv.appendChild(bodyEl);

		// Nach dem Toggle einfügen
		toggle.insertAdjacentElement('afterend', translationDiv);

		// Originale Kinder ausblenden
		Plugins.Translate._setOriginalChildrenVisible(contentEl, false);
	},

	_setOriginalChildrenVisible: function(contentEl, visible) {
		var children = contentEl.children;
		for (var i = 0; i < children.length; i++) {
			var child = children[i];
			// Toggle und Übersetzungsdiv nicht anfassen
			if (child.className === 'translate-toggle' || child.className === 'translate-content') continue;
			child.style.display = visible ? '' : 'none';
		}
	}
};
