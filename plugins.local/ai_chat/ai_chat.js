/* global Plugins, Notify, xhr */

Plugins.Ai_Chat = {
	open: function(id) {
		// Bestehendes Chat-Dialog entfernen
		var existing = document.getElementById('ai-chat-overlay');
		if (existing) existing.remove();
		existing = document.getElementById('ai-chat-dialog');
		if (existing) existing.remove();

		// Overlay
		var overlay = document.createElement('div');
		overlay.id = 'ai-chat-overlay';
		overlay.className = 'ai-chat-overlay';
		overlay.onclick = function() {
			overlay.remove();
			document.getElementById('ai-chat-dialog').remove();
		};

		// Dialog
		var dlg = document.createElement('div');
		dlg.id = 'ai-chat-dialog';
		dlg.className = 'ai-chat-dialog';

		// Header
		var header = document.createElement('div');
		header.className = 'ai-chat-header';

		var icon = document.createElement('i');
		icon.className = 'material-icons';
		icon.textContent = 'forum';
		header.appendChild(icon);

		var title = document.createElement('span');
		title.textContent = 'Fragen zum Artikel';
		header.appendChild(title);

		var closeBtn = document.createElement('i');
		closeBtn.className = 'material-icons';
		closeBtn.textContent = 'close';
		closeBtn.style.cursor = 'pointer';
		closeBtn.style.marginLeft = 'auto';
		closeBtn.onclick = function() {
			overlay.remove();
			dlg.remove();
		};
		header.appendChild(closeBtn);

		dlg.appendChild(header);

		// Messages container
		var messages = document.createElement('div');
		messages.className = 'ai-chat-messages';
		messages.id = 'ai-chat-messages';

		var welcome = document.createElement('div');
		welcome.className = 'ai-chat-bubble ai-chat-ai';
		welcome.textContent = 'Stelle eine Frage zu diesem Artikel. Ich versuche sie basierend auf dem Inhalt zu beantworten.';
		messages.appendChild(welcome);

		dlg.appendChild(messages);

		// Input area
		var inputArea = document.createElement('div');
		inputArea.className = 'ai-chat-input-area';

		var input = document.createElement('input');
		input.type = 'text';
		input.className = 'ai-chat-input';
		input.id = 'ai-chat-input';
		input.placeholder = 'Frage eingeben...';
		input.onkeydown = function(e) {
			if (e.key === 'Enter' && !e.shiftKey) {
				e.preventDefault();
				Plugins.Ai_Chat._send(id);
			}
		};
		inputArea.appendChild(input);

		var sendBtn = document.createElement('button');
		sendBtn.className = 'ai-chat-send-btn';
		var sendIcon = document.createElement('i');
		sendIcon.className = 'material-icons';
		sendIcon.textContent = 'send';
		sendBtn.appendChild(sendIcon);
		sendBtn.onclick = function() {
			Plugins.Ai_Chat._send(id);
		};
		inputArea.appendChild(sendBtn);

		dlg.appendChild(inputArea);

		document.body.appendChild(overlay);
		document.body.appendChild(dlg);

		input.focus();
	},

	_send: function(articleId) {
		var input = document.getElementById('ai-chat-input');
		var question = input.value.trim();
		if (!question) return;

		var messages = document.getElementById('ai-chat-messages');

		// Benutzerfrage anzeigen
		var userBubble = document.createElement('div');
		userBubble.className = 'ai-chat-bubble ai-chat-user';
		userBubble.textContent = question;
		messages.appendChild(userBubble);

		// Ladeanzeige
		var loading = document.createElement('div');
		loading.className = 'ai-chat-bubble ai-chat-ai ai-chat-loading';
		loading.textContent = 'Denke nach...';
		messages.appendChild(loading);

		messages.scrollTop = messages.scrollHeight;

		input.value = '';
		input.disabled = true;

		xhr.json("backend.php", {
			op: "pluginhandler", plugin: "ai_chat", method: "ask",
			article_id: articleId, question: question
		}, function(reply) {
			loading.remove();
			input.disabled = false;
			input.focus();

			var aiBubble = document.createElement('div');
			aiBubble.className = 'ai-chat-bubble ai-chat-ai';

			if (reply.error) {
				aiBubble.textContent = 'Fehler: ' + reply.error;
				aiBubble.classList.add('ai-chat-error');
			} else {
				aiBubble.textContent = reply.answer;
			}

			messages.appendChild(aiBubble);
			messages.scrollTop = messages.scrollHeight;
		});
	}
};
