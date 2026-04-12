Plugins.Save_To_Pkm = {
	_menuVisible: false,

	showMenu: function(event, articleId) {
		event.stopPropagation();
		this.hideMenu();

		xhr.json("backend.php", {
			op: "pluginhandler",
			plugin: "save_to_pkm",
			method: "get_services"
		}, (services) => {
			if (!services || services.length === 0) {
				Notify.error("Keine PKM-Dienste konfiguriert. Bitte in den Einstellungen konfigurieren.");
				return;
			}

			const menu = document.createElement("div");
			menu.id = "pkm-export-menu";
			menu.className = "pkm-dropdown";

			services.forEach((svc) => {
				const item = document.createElement("div");
				item.className = "pkm-menu-item";
				item.textContent = svc.label;
				item.onclick = (e) => {
					e.stopPropagation();
					Plugins.Save_To_Pkm.save(articleId, svc.id, svc.uri_mode || false);
					Plugins.Save_To_Pkm.hideMenu();
				};
				menu.appendChild(item);
			});

			const rect = event.target.getBoundingClientRect();
			menu.style.position = "fixed";
			menu.style.left = rect.left + "px";
			menu.style.top = (rect.bottom + 2) + "px";
			menu.style.zIndex = "10000";

			document.body.appendChild(menu);
			this._menuVisible = true;

			setTimeout(() => {
				document.addEventListener("click", Plugins.Save_To_Pkm._outsideClick, {once: true});
			}, 50);
		});
	},

	_outsideClick: function() {
		Plugins.Save_To_Pkm.hideMenu();
	},

	hideMenu: function() {
		const existing = document.getElementById("pkm-export-menu");
		if (existing) existing.remove();
		this._menuVisible = false;
	},

	save: function(articleId, service, uriMode) {
		Notify.progress("Artikel wird exportiert...");

		xhr.json("backend.php", {
			op: "pluginhandler",
			plugin: "save_to_pkm",
			method: "save_article",
			article_id: articleId,
			service: service
		}, (reply) => {
			if (reply.error) {
				Notify.error(reply.error);
			} else if (reply.uri) {
				// Obsidian URI-Modus: Link öffnen
				Notify.info(reply.message || "Obsidian wird geöffnet...");
				window.open(reply.uri, "_blank");
			} else {
				Notify.info(reply.message || "Exportiert.");
			}
		});
	}
};
