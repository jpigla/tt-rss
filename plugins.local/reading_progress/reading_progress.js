/* global require, Plugins, PluginHost, xhr, App, Notify, __ */

require(['dojo/_base/kernel', 'dojo/ready'], function (dojo, ready) {
	ready(function () {

		Plugins.Reading_Progress = {

			_saveTimers: {},
			_debounceMs: 3000,

			init: function () {
				Plugins.Reading_Progress.bindScrollEvents();
			},

			bindScrollEvents: function () {
				// Scroll-Events für CDM-Modus
				const headlines = document.getElementById('headlines-frame');
				if (headlines) {
					headlines.addEventListener('scroll', function () {
						Plugins.Reading_Progress.handleScroll();
					}, { passive: true });
				}

				// Scroll-Events für Drei-Spalten-Modus
				const content = document.getElementById('content-insert');
				if (content) {
					content.addEventListener('scroll', function () {
						Plugins.Reading_Progress.handleScroll();
					}, { passive: true });
				}
			},

			handleScroll: function () {
				document.querySelectorAll('.rp-progress-container').forEach(function (container) {
					const articleId = container.getAttribute('data-article-id');
					if (!articleId) return;

					const article = container.closest('.cdm, .post');
					if (!article) return;

					const rect = article.getBoundingClientRect();
					const viewportHeight = window.innerHeight;

					// Sichtbaren Bereich des Artikels berechnen
					const articleTop = rect.top;
					const articleHeight = rect.height;

					if (articleHeight <= 0) return;

					// Wie viel des Artikels bereits gescrollt wurde
					let scrolled = Math.max(0, -articleTop + viewportHeight * 0.3);
					let pct = Math.min(100, (scrolled / articleHeight) * 100);

					// Fortschrittsbalken aktualisieren
					const bar = container.querySelector('.rp-progress-bar');
					if (bar) {
						bar.style.width = pct + '%';
					}

					// Debounced speichern
					Plugins.Reading_Progress.debouncedSave(articleId, pct, Math.round(scrolled));
				});
			},

			debouncedSave: function (articleId, pct, position) {
				if (Plugins.Reading_Progress._saveTimers[articleId]) {
					clearTimeout(Plugins.Reading_Progress._saveTimers[articleId]);
				}

				Plugins.Reading_Progress._saveTimers[articleId] = setTimeout(function () {
					Plugins.Reading_Progress.saveProgress(articleId, pct, position);
				}, Plugins.Reading_Progress._debounceMs);
			},

			saveProgress: function (articleId, pct, position) {
				xhr.json("backend.php", App.getPhArgs("reading_progress", "save_progress", {
					ref_id: articleId,
					progress_pct: Math.round(pct * 100) / 100,
					last_position: position
				}), function () {
					// Stilles Speichern, keine Benachrichtigung
				});
			},

			restorePosition: function (articleId) {
				xhr.json("backend.php", App.getPhArgs("reading_progress", "get_progress", {
					ref_id: articleId
				}), function (reply) {
					if (reply && reply.last_position > 0) {
						const container = document.querySelector(
							'.rp-progress-container[data-article-id="' + articleId + '"]');
						if (container) {
							const article = container.closest('.cdm, .post');
							if (article) {
								article.scrollTop = reply.last_position;
							}
						}
					}
				});
			}
		};

		PluginHost.register(PluginHost.HOOK_HEADLINES_RENDERED, function () {
			Plugins.Reading_Progress.bindScrollEvents();
		});

		Plugins.Reading_Progress.init();
	});
});
