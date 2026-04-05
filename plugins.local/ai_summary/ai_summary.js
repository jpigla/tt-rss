/* global Plugins, Notify, xhr */

Plugins.Ai_Summary = {
	generate: function(id, type) {
		type = type || 'short';

		const btn = document.querySelector(`.ai-summary-btn[data-article-id="${id}"]`);
		if (btn) btn.classList.add('ai-summary-loading');

		Notify.progress('Zusammenfassung wird erstellt...');

		xhr.json("backend.php", {
			op: "pluginhandler", plugin: "ai_summary", method: "generate", id: id, type: type
		}, (reply) => {
			if (btn) btn.classList.remove('ai-summary-loading');

			if (reply.error) {
				Notify.error(reply.error);
				return;
			}

			if (reply.summary) {
				Plugins.Ai_Summary._showSummary(id, reply);
				Notify.info(reply.cached ? 'Zusammenfassung aus Cache' : 'Zusammenfassung erstellt');
			}
		});
	},

	_showSummary: function(id, data) {
		// Bestehende Zusammenfassung entfernen
		const existing = document.getElementById('ai-summary-' + id);
		if (existing) existing.remove();

		// Neue Zusammenfassung einfügen
		const box = document.createElement('div');
		box.className = 'ai-summary-box';
		box.id = 'ai-summary-' + id;

		// Header
		const header = document.createElement('div');
		header.className = 'ai-summary-header';

		const icon = document.createElement('i');
		icon.className = 'material-icons';
		icon.textContent = 'auto_awesome';
		header.appendChild(icon);

		const title = document.createElement('span');
		title.textContent = 'KI-Zusammenfassung';
		header.appendChild(title);

		const meta = document.createElement('span');
		meta.className = 'ai-summary-meta';
		meta.textContent = (data.model || '') + (data.cached ? ' (Cache)' : '');
		header.appendChild(meta);

		// Typ-Wechsel-Buttons
		const types = document.createElement('span');
		types.className = 'ai-summary-types';

		['short', 'bullets', 'detailed'].forEach((t) => {
			const typeBtn = document.createElement('button');
			typeBtn.className = 'ai-summary-type-btn' + (t === data.type ? ' active' : '');
			typeBtn.textContent = t === 'short' ? 'Kurz' : (t === 'bullets' ? 'Stichpunkte' : 'Ausführlich');
			typeBtn.onclick = function() { Plugins.Ai_Summary.generate(id, t); };
			types.appendChild(typeBtn);
		});
		header.appendChild(types);

		box.appendChild(header);

		// Content
		const content = document.createElement('div');
		content.className = 'ai-summary-content';
		content.textContent = data.summary;
		box.appendChild(content);

		// In Artikel einfügen
		const article_content = document.querySelector(
			'#RROW-' + id + ' .content-inner'
		) || document.querySelector('#content-insert .post .content');

		if (article_content) {
			article_content.insertBefore(box, article_content.firstChild);
		}
	}
};
