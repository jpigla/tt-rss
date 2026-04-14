/* global require, Plugins, PluginHost, Article */

require(['dojo/_base/kernel', 'dojo/ready'], function (dojo, ready) {
	ready(function () {
		if (typeof PluginHost === 'undefined') return;

		Plugins.ReaderMode = {
			open: function (id) {
				if (!id) id = Article.getActive();
				if (!id) return;
				window.open('plugins.local/reader_mode/reader.php?id=' + id, '_blank');
			}
		};

		// Tastenkürzel 'r' für Reader-Modus
		document.addEventListener('keydown', function (e) {
			if (e.target.matches('input, textarea, select, [contenteditable]')) return;
			if (e.ctrlKey || e.altKey || e.metaKey) return;

			if (e.key === 'r') {
				Plugins.ReaderMode.open();
			}
		});
	});
});
