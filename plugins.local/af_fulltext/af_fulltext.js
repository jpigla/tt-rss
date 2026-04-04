/* global Plugins, Notify, xhr */

Plugins.Af_Fulltext = {
	fetch: function(id) {
		const btn = document.querySelector(`.af-fulltext-btn[data-article-id="${id}"]`);
		if (btn) {
			btn.classList.add('af-fulltext-loading');
		}

		Notify.progress('Volltext wird geladen...');

		xhr.json("backend.php", {op: "pluginhandler", plugin: "af_fulltext", method: "fetch", id: id}, (reply) => {
			if (btn) {
				btn.classList.remove('af-fulltext-loading');
			}

			if (reply.error) {
				Notify.error(reply.error);
				return;
			}

			if (reply.content) {
				// Content wurde serverseitig durch Sanitizer::sanitize() bereinigt.
				// TT-RSS nutzt dieselbe Methode für alle Artikel-Inhalte.
				const content_el = document.querySelector(
					`#RROW-${id} .content-inner` // CDM-Modus
				) || document.querySelector(
					`#content-insert` // Drei-Panel-Modus
				);

				if (content_el) {
					// Sicher: Inhalt wurde serverseitig sanitized (gleicher Codepath wie normaler Feed-Content)
					content_el.innerHTML = reply.content; // eslint-disable-line no-unsanitized/property
					if (btn) {
						btn.classList.add('af-fulltext-loaded');
						btn.title = 'Volltext geladen';
					}
					Notify.info(reply.cached ? 'Volltext aus Cache geladen' : 'Volltext erfolgreich geladen');
				}
			}
		});
	}
};
