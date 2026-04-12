Plugins.Feed_Discovery = {

	showPanel: function() {
		const dialog = new dijit.Dialog({
			title: "Entdecken",
			style: "width: 700px; max-height: 85vh;",
			content: '<div class="fd-panel">'
				+ '<div class="fd-tabs">'
				+ '<button class="fd-tab fd-tab-active" onclick="Plugins.Feed_Discovery._switchTab(this, \'trending\')">Trending Topics</button>'
				+ '<button class="fd-tab" onclick="Plugins.Feed_Discovery._switchTab(this, \'suggestions\')">Feed-Vorschläge</button>'
				+ '</div>'
				+ '<div id="fd-content" class="fd-content"><div class="fd-loading">Wird geladen...</div></div>'
				+ '</div>'
		});

		dialog.show();
		this._loadTrending();
	},

	_switchTab: function(btn, tab) {
		document.querySelectorAll(".fd-tab").forEach(t => t.classList.remove("fd-tab-active"));
		btn.classList.add("fd-tab-active");

		if (tab === "trending") {
			this._loadTrending();
		} else {
			this._loadSuggestions();
		}
	},

	_setContent: function(html) {
		const container = document.getElementById("fd-content");
		if (container) container.innerHTML = html;
	},

	_loadTrending: function() {
		this._setContent('<div class="fd-loading">Wird geladen...</div>');

		xhr.json("backend.php", {
			op: "pluginhandler",
			plugin: "feed_discovery",
			method: "get_trending"
		}, (topics) => {
			if (!topics || topics.length === 0) {
				this._setContent('<div class="fd-empty">'
					+ '<i class="material-icons">trending_flat</i>'
					+ '<p>Noch keine Trending Topics. Daten werden beim nächsten Housekeeping berechnet.</p>'
					+ '</div>');
				return;
			}

			const container = document.getElementById("fd-content");
			if (!container) return;

			// Container leeren und DOM-basiert aufbauen
			container.textContent = "";

			const wrapper = document.createElement("div");
			wrapper.className = "fd-topics";

			// Tag-Cloud
			const maxCount = Math.max(...topics.map(t => t.count));
			const cloud = document.createElement("div");
			cloud.className = "fd-tag-cloud";

			topics.forEach((t) => {
				const size = 12 + Math.round((t.count / maxCount) * 16);
				const opacity = 0.5 + (t.count / maxCount) * 0.5;
				const tag = document.createElement("span");
				tag.className = "fd-tag";
				tag.style.fontSize = size + "px";
				tag.style.opacity = opacity;
				tag.title = t.count + " Artikel";
				tag.textContent = t.topic;
				cloud.appendChild(tag);
				cloud.appendChild(document.createTextNode(" "));
			});
			wrapper.appendChild(cloud);

			// Liste
			const list = document.createElement("div");
			list.className = "fd-topic-list";

			topics.forEach((t) => {
				const item = document.createElement("div");
				item.className = "fd-topic-item";

				const header = document.createElement("div");
				header.className = "fd-topic-header";

				const name = document.createElement("span");
				name.className = "fd-topic-name";
				name.textContent = t.topic;
				header.appendChild(name);

				const count = document.createElement("span");
				count.className = "fd-topic-count";
				count.textContent = t.count + " Artikel";
				header.appendChild(count);

				item.appendChild(header);

				if (t.samples && t.samples.length > 0) {
					const samples = document.createElement("div");
					samples.className = "fd-topic-samples";
					t.samples.forEach((s) => {
						const sample = document.createElement("div");
						sample.className = "fd-sample";
						sample.textContent = s;
						samples.appendChild(sample);
					});
					item.appendChild(samples);
				}

				list.appendChild(item);
			});
			wrapper.appendChild(list);
			container.appendChild(wrapper);
		});
	},

	_loadSuggestions: function() {
		this._setContent('<div class="fd-loading">Wird geladen...</div>');

		xhr.json("backend.php", {
			op: "pluginhandler",
			plugin: "feed_discovery",
			method: "get_suggestions"
		}, (suggestions) => {
			if (!suggestions || suggestions.length === 0) {
				this._setContent('<div class="fd-empty">'
					+ '<i class="material-icons">rss_feed</i>'
					+ '<p>Keine Feed-Vorschläge verfügbar.</p>'
					+ '</div>');
				return;
			}

			const container = document.getElementById("fd-content");
			if (!container) return;
			container.textContent = "";

			const wrapper = document.createElement("div");
			wrapper.className = "fd-suggestions";

			suggestions.forEach((s) => {
				const card = document.createElement("div");
				card.className = "fd-suggestion";
				card.id = "fd-sug-" + (s.id || 0);

				const header = document.createElement("div");
				header.className = "fd-sug-header";
				const titleEl = document.createElement("strong");
				titleEl.textContent = s.feed_title || s.feed_url;
				header.appendChild(titleEl);
				card.appendChild(header);

				const urlEl = document.createElement("div");
				urlEl.className = "fd-sug-url";
				urlEl.textContent = s.feed_url;
				card.appendChild(urlEl);

				const reason = document.createElement("div");
				reason.className = "fd-sug-reason";
				reason.textContent = s.reason;
				card.appendChild(reason);

				const actions = document.createElement("div");
				actions.className = "fd-sug-actions";

				const subBtn = document.createElement("button");
				subBtn.className = "fd-btn fd-btn-subscribe";
				subBtn.textContent = "Abonnieren";
				subBtn.addEventListener("click", () => {
					Plugins.Feed_Discovery._subscribe(s.id || 0, s.feed_url, card);
				});
				actions.appendChild(subBtn);

				if (s.id > 0) {
					const dismissBtn = document.createElement("button");
					dismissBtn.className = "fd-btn fd-btn-dismiss";
					dismissBtn.textContent = "Ausblenden";
					dismissBtn.addEventListener("click", () => {
						Plugins.Feed_Discovery._dismiss(s.id, card);
					});
					actions.appendChild(dismissBtn);
				}

				card.appendChild(actions);
				wrapper.appendChild(card);
			});

			container.appendChild(wrapper);
		});
	},

	_subscribe: function(id, feedUrl, cardEl) {
		Notify.progress("Feed wird abonniert...");
		xhr.json("backend.php", {
			op: "pluginhandler",
			plugin: "feed_discovery",
			method: "subscribe_suggestion",
			suggestion_id: id,
			feed_url: feedUrl
		}, (reply) => {
			if (reply.error) {
				Notify.error(reply.error);
			} else {
				Notify.info(reply.message || "Abonniert.");
				if (cardEl) {
					cardEl.style.opacity = "0.5";
					const actions = cardEl.querySelector(".fd-sug-actions");
					if (actions) {
						actions.textContent = "";
						const done = document.createElement("span");
						done.style.color = "#40c463";
						done.textContent = "\u2713 Abonniert";
						actions.appendChild(done);
					}
				}
			}
		});
	},

	_dismiss: function(id, cardEl) {
		xhr.json("backend.php", {
			op: "pluginhandler",
			plugin: "feed_discovery",
			method: "dismiss_suggestion",
			suggestion_id: id
		}, () => {
			if (cardEl) cardEl.remove();
		});
	}
};
