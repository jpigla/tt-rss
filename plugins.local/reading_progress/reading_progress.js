/* global require, Plugins, PluginHost, xhr, App, Notify, __ */

require(['dojo/_base/kernel', 'dojo/ready'], function (dojo, ready) {
	ready(function () {

		Plugins.Reading_Progress = {

			_saveTimers: {},
			_debounceMs: 3000,

			init: function () {
				Plugins.Reading_Progress.bindScrollEvents();
			},

			/**
			 * Fortschrittsbalken in den Header des übergebenen Containers verschieben.
			 * Drei-Spalten: container = c.domNode (#content-insert), Balken in .post .content
			 * CDM:          container = .cdm-Zeile, Balken in .content-inner
			 */
			moveProgressBarsToHeader: function (container) {
				const root = container || document;

				// Drei-Spalten-Modus: Balken direkt in .content
				root.querySelectorAll('.post .content > .rp-progress-container').forEach(function (bar) {
					const header = bar.closest('.post').querySelector('.header');
					if (!header) return;
					header.appendChild(bar);
				});

				// CDM-Modus: Balken in .content-inner (lazy-loaded)
				root.querySelectorAll('.cdm .content-inner > .rp-progress-container').forEach(function (bar) {
					const header = bar.closest('.cdm').querySelector('.header');
					if (!header) return;
					header.appendChild(bar);
				});
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

					// Scroll-Container je nach Modus
					const isPost = article.classList.contains('post');
					const scrollEl = document.getElementById(isPost ? 'content-insert' : 'headlines-frame');
					if (!scrollEl) return;

					const articleRect = article.getBoundingClientRect();
					const containerRect = scrollEl.getBoundingClientRect();

					if (articleRect.height <= 0) return;

					// Wie weit der Artikel-Anfang über den oberen Rand des Containers hinausgescrollt ist
					const scrolledPast = Math.max(0, containerRect.top - articleRect.top);
					// Scrollbare Strecke = Artikelhöhe minus Container-Höhe (letzter sichtbarer Ausschnitt)
					const readableHeight = Math.max(1, articleRect.height - scrollEl.clientHeight);

					const pct = Math.min(100, (scrolledPast / readableHeight) * 100);

					// Fortschrittsbalken aktualisieren
					const bar = container.querySelector('.rp-progress-bar');
					if (bar) {
						bar.style.width = pct + '%';
					}

					// Debounced speichern (scrolledPast als Pixel-Position)
					Plugins.Reading_Progress.debouncedSave(articleId, pct, Math.round(scrolledPast));
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

		// Drei-Spalten-Modus: Artikel wurde gerendert, c.domNode wird übergeben
		PluginHost.register(PluginHost.HOOK_ARTICLE_RENDERED, function (domNode) {
			Plugins.Reading_Progress.moveProgressBarsToHeader(domNode);
			Plugins.Reading_Progress.bindScrollEvents();
		});

		// CDM-Modus: Artikel wurde entpackt, .cdm-Zeile wird übergeben
		PluginHost.register(PluginHost.HOOK_ARTICLE_RENDERED_CDM, function (row) {
			Plugins.Reading_Progress.moveProgressBarsToHeader(row);
		});

		Plugins.Reading_Progress.init();
	});
});
