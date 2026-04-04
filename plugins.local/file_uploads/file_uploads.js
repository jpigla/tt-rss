/* global require, Plugins, PluginHost, xhr, App, Notify, fox, __ */

require(['dojo/_base/kernel', 'dojo/ready'], function (dojo, ready) {
	ready(function () {

		Plugins.File_Uploads = {

			showUploadDialog: function () {
				const content = document.createElement('div');

				const dropZone = document.createElement('div');
				dropZone.className = 'fu-drop-zone';

				const dropLabel = document.createElement('p');
				dropLabel.textContent = __('Datei hierher ziehen oder klicken zum Auswählen');
				dropZone.appendChild(dropLabel);

				const dropHint = document.createElement('p');
				dropHint.className = 'fu-hint';
				dropHint.textContent = __('Erlaubt: PDF, TXT, HTML, DOCX (max. 10 MB)');
				dropZone.appendChild(dropHint);

				const fileInput = document.createElement('input');
				fileInput.type = 'file';
				fileInput.accept = '.txt,.html,.htm,.pdf,.docx';
				fileInput.style.display = 'none';
				fileInput.id = 'fu-file-input';

				dropZone.addEventListener('click', function () {
					fileInput.click();
				});

				dropZone.addEventListener('dragover', function (e) {
					e.preventDefault();
					dropZone.classList.add('fu-drag-over');
				});

				dropZone.addEventListener('dragleave', function () {
					dropZone.classList.remove('fu-drag-over');
				});

				dropZone.addEventListener('drop', function (e) {
					e.preventDefault();
					dropZone.classList.remove('fu-drag-over');

					if (e.dataTransfer && e.dataTransfer.files.length > 0) {
						Plugins.File_Uploads.uploadFile(e.dataTransfer.files[0]);
					}
				});

				fileInput.addEventListener('change', function () {
					if (fileInput.files && fileInput.files.length > 0) {
						Plugins.File_Uploads.uploadFile(fileInput.files[0]);
					}
				});

				content.appendChild(dropZone);
				content.appendChild(fileInput);

				const footer = document.createElement('footer');
				footer.className = 'text-center';
				const closeBtn = document.createElement('button');
				closeBtn.setAttribute('dojoType', 'dijit.form.Button');
				closeBtn.setAttribute('type', 'submit');
				closeBtn.textContent = __('Schließen');
				footer.appendChild(closeBtn);
				content.appendChild(footer);

				const dialog = new fox.SingleUseDialog({
					title: __('Datei hochladen'),
					content: content
				});

				dialog.show();
			},

			uploadFile: function (file) {
				if (!file) return;

				// Größenprüfung
				if (file.size > 10 * 1024 * 1024) {
					Notify.error(__('Datei ist zu groß (maximal 10 MB)'));
					return;
				}

				Notify.progress(__('Lade hoch...'), true);

				const formData = new FormData();
				formData.append('op', 'pluginhandler');
				formData.append('plugin', 'file_uploads');
				formData.append('method', 'upload');
				formData.append('file', file);

				fetch('backend.php', {
					method: 'POST',
					body: formData
				})
				.then(function (response) { return response.json(); })
				.then(function (reply) {
					Notify.close();
					if (reply && reply.status === 'ok') {
						Notify.info(reply.message || __('Datei hochgeladen'));
					} else {
						Notify.error(reply ? reply.error : __('Fehler beim Hochladen'));
					}
				})
				.catch(function () {
					Notify.close();
					Notify.error(__('Fehler beim Hochladen'));
				});
			},

			deleteUpload: function (id) {
				if (!confirm(__('Upload wirklich löschen?'))) return;

				xhr.json("backend.php", App.getPhArgs("file_uploads", "delete_upload", {
					id: id
				}), function (reply) {
					if (reply && reply.status === 'ok') {
						Notify.info(__('Upload gelöscht'));
						window.location.reload();
					}
				});
			}
		};
	});
});
