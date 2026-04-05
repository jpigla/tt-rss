/* global Plugins, Notify, xhr */

Plugins.Af_Fulltext = {
	fetch: function(id) {
		const btn = document.querySelector(`.af-fulltext-btn[data-article-id="${id}"]`);

		// Toggle: Wenn bereits geladen, Originalinhalt wiederherstellen
		if (btn && btn.classList.contains('af-fulltext-loaded')) {
			const content_el = this._getContentElement(id);
			if (content_el && content_el.dataset.afOriginal) {
				content_el.innerHTML = content_el.dataset.afOriginal;
				delete content_el.dataset.afOriginal;
				btn.classList.remove('af-fulltext-loaded');
				btn.title = 'Volltext laden';
				Notify.info('Originalinhalt wiederhergestellt');
				return;
			}
		}

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
				const content_el = this._getContentElement(id);

				if (content_el) {
					// Originalinhalt sichern, falls noch nicht geschehen
					if (!content_el.dataset.afOriginal) {
						content_el.dataset.afOriginal = content_el.innerHTML;
					}

					// Content wurde serverseitig durch Sanitizer::sanitize() bereinigt.
					// TT-RSS nutzt dieselbe Methode für alle Artikel-Inhalte (Feed-Content,
					// gecachte Inhalte, etc.) -- identischer Codepath, kein zusätzliches Risiko.
					content_el.innerHTML = reply.content; // eslint-disable-line no-unsanitized/property
					if (btn) {
						btn.classList.add('af-fulltext-loaded');
						btn.title = 'Klicken für Originalinhalt';
					}
					Notify.info(reply.cached ? 'Volltext aus Cache geladen' : 'Volltext erfolgreich geladen');
				}
			}
		});
	},

	_getContentElement: function(id) {
		// CDM-Modus: nur den inneren Content-Bereich
		return document.querySelector(`#RROW-${id} .content-inner`)
			// Drei-Panel-Modus: nur den .content-Bereich innerhalb des Posts
			|| document.querySelector(`#content-insert .post .content`);
	}
};
