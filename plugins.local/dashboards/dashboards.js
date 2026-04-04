/* global require, Plugins, PluginHost, xhr, App, Notify, fox, __ */

require(['dojo/_base/kernel', 'dojo/ready'], function (dojo, ready) {
	ready(function () {

		Plugins.Dashboards = {

			_currentId: null,

			show: function (dashboardId) {
				dashboardId = dashboardId || 0;

				xhr.json("backend.php", App.getPhArgs("dashboards", "get_dashboard", {
					id: dashboardId
				}), function (reply) {
					if (!reply) return;

					Plugins.Dashboards._currentId = reply.id;
					Plugins.Dashboards.render(reply);
				});
			},

			render: function (data) {
				const container = document.getElementById('headlines-frame') ||
					document.getElementById('content-insert');
				if (!container) return;

				// Container leeren
				while (container.firstChild) {
					container.removeChild(container.firstChild);
				}

				const dashboard = document.createElement('div');
				dashboard.className = 'db-dashboard';

				const header = document.createElement('div');
				header.className = 'db-header';
				const h2 = document.createElement('h2');
				h2.textContent = data.title;
				header.appendChild(h2);
				dashboard.appendChild(header);

				const grid = document.createElement('div');
				grid.className = 'db-grid';

				if (data.widgets && data.widgets.length > 0) {
					data.widgets.forEach(function (widget) {
						grid.appendChild(Plugins.Dashboards.renderWidget(widget));
					});
				} else {
					const p = document.createElement('p');
					p.textContent = __('Keine Widgets konfiguriert.');
					grid.appendChild(p);
				}

				dashboard.appendChild(grid);
				container.appendChild(dashboard);
			},

			renderWidget: function (widget) {
				const card = document.createElement('div');
				card.className = 'db-widget db-widget-' + widget.type;

				const hdr = document.createElement('div');
				hdr.className = 'db-widget-header';
				hdr.textContent = widget.title;
				card.appendChild(hdr);

				const content = document.createElement('div');
				content.className = 'db-widget-content';

				switch (widget.type) {
					case 'unread_stats':
						if (widget.data && widget.data.feeds) {
							const ul = document.createElement('ul');
							ul.className = 'db-feed-list';
							widget.data.feeds.forEach(function (feed) {
								const li = document.createElement('li');
								const title = document.createElement('span');
								title.className = 'db-feed-title';
								title.textContent = feed.title;
								const count = document.createElement('span');
								count.className = 'db-feed-count';
								count.textContent = feed.unread;
								li.appendChild(title);
								li.appendChild(count);
								ul.appendChild(li);
							});
							content.appendChild(ul);
							if (widget.data.feeds.length === 0) {
								const p = document.createElement('p');
								p.className = 'db-empty';
								p.textContent = __('Keine ungelesenen Artikel');
								content.appendChild(p);
							}
						}
						break;

					case 'recent_articles':
						if (widget.data && widget.data.articles) {
							const ul = document.createElement('ul');
							ul.className = 'db-article-list';
							widget.data.articles.forEach(function (article) {
								const li = document.createElement('li');
								const a = document.createElement('a');
								a.href = article.link || '#';
								a.target = '_blank';
								a.rel = 'noopener';
								a.textContent = article.title;
								const meta = document.createElement('span');
								meta.className = 'db-meta';
								meta.textContent = article.feed_title + ' - ' + article.updated;
								li.appendChild(a);
								li.appendChild(meta);
								ul.appendChild(li);
							});
							content.appendChild(ul);
						}
						break;

					case 'label_breakdown':
						if (widget.data && widget.data.labels) {
							const ul = document.createElement('ul');
							ul.className = 'db-label-list';
							widget.data.labels.forEach(function (label) {
								const li = document.createElement('li');
								const chip = document.createElement('span');
								chip.className = 'db-label-chip';
								if (label.bg_color) {
									chip.style.background = label.bg_color;
									chip.style.color = label.fg_color || '#fff';
								}
								chip.textContent = label.caption;
								const cnt = document.createElement('span');
								cnt.className = 'db-label-count';
								cnt.textContent = label.article_count;
								li.appendChild(chip);
								li.appendChild(cnt);
								ul.appendChild(li);
							});
							content.appendChild(ul);
							if (widget.data.labels.length === 0) {
								const p = document.createElement('p');
								p.className = 'db-empty';
								p.textContent = __('Keine Labels vorhanden');
								content.appendChild(p);
							}
						}
						break;

					case 'reading_stats':
						if (widget.data) {
							const grid = document.createElement('div');
							grid.className = 'db-stats-grid';

							[
								[widget.data.today, __('Heute')],
								[widget.data.week, __('Diese Woche')],
								[widget.data.month, __('Dieser Monat')]
							].forEach(function (item) {
								const stat = document.createElement('div');
								stat.className = 'db-stat';
								const val = document.createElement('span');
								val.className = 'db-stat-value';
								val.textContent = item[0];
								const lbl = document.createElement('span');
								lbl.className = 'db-stat-label';
								lbl.textContent = item[1];
								stat.appendChild(val);
								stat.appendChild(lbl);
								grid.appendChild(stat);
							});

							content.appendChild(grid);
						}
						break;
				}

				card.appendChild(content);
				return card;
			},

			createNew: function () {
				const title = prompt(__('Titel des neuen Dashboards:'), __('Neues Dashboard'));
				if (!title) return;

				xhr.json("backend.php", App.getPhArgs("dashboards", "create_dashboard", {
					title: title
				}), function (reply) {
					if (reply && reply.status === 'ok') {
						Notify.info(__('Dashboard erstellt'));
						window.location.reload();
					}
				});
			},

			deleteDashboard: function (id) {
				if (!confirm(__('Dashboard wirklich löschen?'))) return;

				xhr.json("backend.php", App.getPhArgs("dashboards", "delete_dashboard", {
					id: id
				}), function (reply) {
					if (reply && reply.status === 'ok') {
						Notify.info(__('Dashboard gelöscht'));
						window.location.reload();
					}
				});
			}
		};
	});
});
