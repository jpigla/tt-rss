Plugins.Youtube_Sync = {
	importOPML: function() {
		const fileInput = document.getElementById('yt_opml_file');
		if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
			Notify.error("Bitte eine OPML-Datei auswählen.");
			return;
		}

		const formData = new FormData();
		formData.append('op', 'pluginhandler');
		formData.append('plugin', 'youtube_sync');
		formData.append('method', 'import_opml');
		formData.append('opml_file', fileInput.files[0]);

		const categoryInput = document.querySelector('input[name="yt_category"]');
		if (categoryInput) {
			formData.append('yt_category', categoryInput.value || 'YouTube');
		}

		Notify.progress("Importiere OPML...", true);

		fetch("backend.php", {
			method: "POST",
			body: formData
		})
		.then(response => response.text())
		.then(reply => {
			Notify.info(reply);
		})
		.catch(err => {
			Notify.error("Fehler beim Import: " + err.message);
		});
	}
};
