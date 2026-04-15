/* global Plugins, __, Notify, xhr */

/**
 * Parser Rules -- Prefs-Tab-Logik (Regelliste, Toggle, Test, Regenerierung).
 * Wird über get_js() in die Hauptanwendung eingebettet (index.php / prefs.php).
 * Dojo/AMD-Kontext: Plugins, xhr, Notify, __ sind verfügbar.
 */
define(['dojo/_base/declare'], function (declare) {

	Plugins.Parser_Rules = {

		loadPrefsRules: function () {
			var container = document.getElementById('parser-rules-list');
			if (!container) return;

			xhr.json('backend.php', {op: 'pluginhandler', plugin: 'parser_rules', method: 'get_rules'},
				function (data) {
					if (!data || !data.rules) {
						container.textContent = __('Fehler beim Laden der Regeln.');
						return;
					}

					if (data.rules.length === 0) {
						container.textContent = __('Keine Regeln vorhanden. Öffne einen Artikel im Reader Mode und wähle Text aus, um eine Extraktionsregel zu erstellen.');
						return;
					}

					Plugins.Parser_Rules._renderRulesTable(container, data.rules);
					Plugins.Parser_Rules._bindPrefsEvents(container);
				}
			);
		},

		_renderRulesTable: function (container, rules) {
			while (container.firstChild) container.removeChild(container.firstChild);

			var table = document.createElement('table');
			table.className = 'pr-rules-table';
			table.setAttribute('width', '100%');

			// Thead
			var thead = document.createElement('thead');
			var headRow = document.createElement('tr');
			[__('Domain'), __('Typ'), __('Selektor'), __('Konfidenz'),
			 __('Treffer/Fehler'), __('Aktiv'), __('Aktionen')].forEach(function (label) {
				var th = document.createElement('th');
				th.textContent = label;
				headRow.appendChild(th);
			});
			thead.appendChild(headRow);
			table.appendChild(thead);

			// Tbody
			var tbody = document.createElement('tbody');

			rules.forEach(function (r) {
				var effConf = r.confidence * (parseInt(r.hit_count) + 1) /
					(parseInt(r.hit_count) + parseInt(r.miss_count) + 1);
				var confClass = effConf >= 0.6 ? 'pr-conf-high' :
					(effConf >= 0.3 ? 'pr-conf-mid' : 'pr-conf-low');
				var isActive = r.is_active === 't' || r.is_active === true;

				var tr = document.createElement('tr');
				if (!isActive) tr.className = 'pr-rule-inactive';
				tr.setAttribute('data-rule-id', r.id);

				// Domain
				var tdDomain = document.createElement('td');
				tdDomain.textContent = r.domain;
				tr.appendChild(tdDomain);

				// Typ-Badge
				var tdType = document.createElement('td');
				var badge = document.createElement('span');
				badge.className = 'pr-badge pr-badge-' + r.rule_type;
				badge.textContent = r.rule_type === 'include' ? __('Inhalt') : __('Entfernen');
				tdType.appendChild(badge);
				tr.appendChild(tdType);

				// Selektor
				var tdSel = document.createElement('td');
				tdSel.className = 'pr-selector-cell';
				tdSel.title = r.selector_css || '';
				tdSel.textContent = r.selector_css || r.selector_xpath;
				tr.appendChild(tdSel);

				// Konfidenz
				var tdConf = document.createElement('td');
				var confSpan = document.createElement('span');
				confSpan.className = confClass;
				confSpan.textContent = Math.round(effConf * 100) + '%';
				tdConf.appendChild(confSpan);
				tr.appendChild(tdConf);

				// Treffer/Fehler
				var tdHits = document.createElement('td');
				tdHits.textContent = r.hit_count + ' / ' + r.miss_count;
				tr.appendChild(tdHits);

				// Toggle
				var tdActive = document.createElement('td');
				var toggle = document.createElement('input');
				toggle.type = 'checkbox';
				toggle.className = 'pr-toggle';
				toggle.checked = isActive;
				tdActive.appendChild(toggle);
				tr.appendChild(tdActive);

				// Aktionen
				var tdActions = document.createElement('td');
				tdActions.className = 'pr-actions-cell';

				[{cls: 'pr-test', icon: 'science', title: __('Testen')},
				 {cls: 'pr-regen', icon: 'refresh', title: __('Regenerieren')},
				 {cls: 'pr-delete', icon: 'delete', title: __('Löschen')}
				].forEach(function (a) {
					var abtn = document.createElement('button');
					abtn.className = 'pr-action-btn ' + a.cls;
					abtn.title = a.title;
					var aicon = document.createElement('i');
					aicon.className = 'material-icons';
					aicon.textContent = a.icon;
					abtn.appendChild(aicon);
					tdActions.appendChild(abtn);
				});
				tr.appendChild(tdActions);

				tbody.appendChild(tr);

				// Begründungs-Zeile
				if (r.llm_reasoning) {
					var trReason = document.createElement('tr');
					trReason.className = 'pr-reasoning-row' + (!isActive ? ' pr-rule-inactive' : '');
					var tdReason = document.createElement('td');
					tdReason.setAttribute('colspan', '7');
					var small = document.createElement('small');
					small.textContent = r.llm_reasoning;
					tdReason.appendChild(small);
					trReason.appendChild(tdReason);
					tbody.appendChild(trReason);
				}
			});

			table.appendChild(tbody);
			container.appendChild(table);
		},

		_bindPrefsEvents: function (container) {
			var self = this;

			container.querySelectorAll('.pr-toggle').forEach(function (cb) {
				cb.addEventListener('change', function () {
					var id = this.closest('tr').getAttribute('data-rule-id');
					xhr.post('backend.php', {
						op: 'pluginhandler', plugin: 'parser_rules',
						method: 'toggle_rule', id: id
					}, function () {
						self.loadPrefsRules();
					});
				});
			});

			container.querySelectorAll('.pr-test').forEach(function (btn) {
				btn.addEventListener('click', function () {
					var id = this.closest('tr').getAttribute('data-rule-id');
					Notify.progress(__('Teste Regel\u2026'));
					xhr.post('backend.php', {
						op: 'pluginhandler', plugin: 'parser_rules',
						method: 'test_rule', id: id
					}, function (data) {
						if (data.error) {
							Notify.error(data.error);
						} else {
							Notify.info(__('Treffer: ') + data.elements_found +
								' | ' + data.content_length + __(' Zeichen'));
						}
					});
				});
			});

			container.querySelectorAll('.pr-regen').forEach(function (btn) {
				btn.addEventListener('click', function () {
					var id = this.closest('tr').getAttribute('data-rule-id');
					Notify.progress(__('Regeneriere Regel per KI\u2026'));
					xhr.post('backend.php', {
						op: 'pluginhandler', plugin: 'parser_rules',
						method: 'regenerate_rule', id: id
					}, function (data) {
						if (data.error) {
							Notify.error(data.error);
						} else {
							Notify.info(__('Regel regeneriert: ') + data.css_selector +
								' (Konfidenz: ' + Math.round(data.confidence * 100) + '%)');
							self.loadPrefsRules();
						}
					});
				});
			});

			container.querySelectorAll('.pr-delete').forEach(function (btn) {
				btn.addEventListener('click', function () {
					if (!confirm(__('Regel wirklich löschen?'))) return;
					var id = this.closest('tr').getAttribute('data-rule-id');
					xhr.post('backend.php', {
						op: 'pluginhandler', plugin: 'parser_rules',
						method: 'delete_rule', id: id
					}, function () {
						Notify.info(__('Regel gelöscht'));
						self.loadPrefsRules();
					});
				});
			});
		}
	};

	return declare('plugins.parser_rules', null, {});
});
