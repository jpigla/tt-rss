/* global Plugins, Notify, xhr, Headlines, App */

Plugins.Read_Later = {
	toggle: function(id) {
		xhr.json("backend.php", {op: "pluginhandler", plugin: "read_later", method: "toggle", id: id}, (reply) => {
			if (reply) {
				const btn = document.querySelector(`.read-later-btn[data-article-id="${id}"]`);
				if (btn) {
					if (reply.saved) {
						btn.textContent = 'bookmark';
						btn.classList.add('read-later-active');
						btn.title = 'Aus Leseliste entfernen';
						Notify.info('Zum Später-Lesen gespeichert');
					} else {
						btn.textContent = 'bookmark_border';
						btn.classList.remove('read-later-active');
						btn.title = 'Später lesen';
						Notify.info('Aus Leseliste entfernt');
					}
				}
			}
		});
	}
};

// Hotkey-Handler registrieren
App.hotkey_actions["read_later_toggle"] = () => {
	const id = Headlines.getActive();
	if (id) {
		Plugins.Read_Later.toggle(id);
	}
};
