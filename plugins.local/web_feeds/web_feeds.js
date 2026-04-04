Plugins.WebFeeds = {
	preview: function(feedId) {
		const listSel = dijit.byId('web_feeds_list_selector');
		const titleSel = dijit.byId('web_feeds_title_selector');

		if (!listSel || !titleSel) {
			Notify.error('Selektoren nicht gefunden.');
			return;
		}

		Notify.progress('Vorschau wird geladen...');

		xhr.json("backend.php", {
			op: "pluginhandler",
			plugin: "web_feeds",
			method: "preview",
			feed_id: feedId,
			list_selector: listSel.get('value'),
			title_selector: titleSel.get('value')
		}, (reply) => {
			if (reply.error) {
				Notify.error(reply.error);
			} else {
				Notify.info('Gefunden: ' + reply.count + ' Einträge');
			}
		});
	}
};
