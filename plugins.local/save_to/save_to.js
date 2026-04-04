Plugins.Save_To = {
	_menuVisible: false,

	showMenu: function(event, articleId) {
		event.stopPropagation();

		// Bestehendes Menü entfernen
		this.hideMenu();

		xhr.json("backend.php", {
			op: "pluginhandler",
			plugin: "save_to",
			method: "get_services"
		}, (services) => {
			if (!services || services.length === 0) {
				Notify.error("Keine Dienste konfiguriert. Bitte in den Einstellungen konfigurieren.");
				return;
			}

			const menu = document.createElement("div");
			menu.id = "save-to-menu";
			menu.className = "save-to-dropdown";

			services.forEach((svc) => {
				const item = document.createElement("div");
				item.className = "save-to-menu-item";
				item.textContent = svc.label;
				item.onclick = (e) => {
					e.stopPropagation();
					Plugins.Save_To.save(articleId, svc.id);
					Plugins.Save_To.hideMenu();
				};
				menu.appendChild(item);
			});

			// Position relativ zum Button
			const rect = event.target.getBoundingClientRect();
			menu.style.position = "fixed";
			menu.style.left = rect.left + "px";
			menu.style.top = (rect.bottom + 2) + "px";
			menu.style.zIndex = "10000";

			document.body.appendChild(menu);
			this._menuVisible = true;

			// Bei Klick ausserhalb schliessen
			setTimeout(() => {
				document.addEventListener("click", Plugins.Save_To._outsideClick, {once: true});
			}, 50);
		});
	},

	_outsideClick: function() {
		Plugins.Save_To.hideMenu();
	},

	hideMenu: function() {
		const existing = document.getElementById("save-to-menu");
		if (existing) existing.remove();
		this._menuVisible = false;
	},

	save: function(articleId, service) {
		Notify.progress("Artikel wird gespeichert...");

		xhr.json("backend.php", {
			op: "pluginhandler",
			plugin: "save_to",
			method: "save_article",
			article_id: articleId,
			service: service
		}, (reply) => {
			if (reply.error) {
				Notify.error(reply.error);
			} else {
				Notify.info(reply.message || "Gespeichert.");
			}
		});
	}
};
