/* global Plugins, Notify */

Plugins.Tts = {
	_currentUtterance: null,
	_rate: 1.0,
	_playing: false,
	_articleId: null,

	speak: function(id) {
		if (!window.speechSynthesis) {
			Notify.error('Text-to-Speech wird von diesem Browser nicht unterstützt.');
			return;
		}

		// Falls gerade derselbe Artikel spricht -> Pause/Resume
		if (Plugins.Tts._articleId === id && Plugins.Tts._playing) {
			Plugins.Tts.pause();
			return;
		}

		// Laufende Sprachausgabe stoppen
		window.speechSynthesis.cancel();

		// Artikeltext extrahieren
		var contentEl = document.querySelector(
			'#RROW-' + id + ' .content-inner'
		) || document.querySelector('#content-insert');

		if (!contentEl) {
			Notify.error('Artikelinhalt nicht gefunden.');
			return;
		}

		var text = contentEl.textContent || contentEl.innerText;
		text = text.trim();

		if (!text) {
			Notify.error('Kein Text zum Vorlesen gefunden.');
			return;
		}

		// Text in Sätze aufteilen (Web Speech API hat Längenlimit)
		var sentences = Plugins.Tts._splitText(text);

		Plugins.Tts._articleId = id;
		Plugins.Tts._playing = true;
		Plugins.Tts._showPlayer(id);

		Plugins.Tts._speakSentences(sentences, 0);
	},

	_splitText: function(text) {
		// In Abschnitte von ca. 200 Zeichen aufteilen (Satzgrenzen beachten)
		var chunks = [];
		var sentences = text.replace(/([.!?])\s+/g, '$1\n').split('\n');

		var current = '';
		for (var i = 0; i < sentences.length; i++) {
			var s = sentences[i].trim();
			if (!s) continue;

			if (current.length + s.length > 200 && current.length > 0) {
				chunks.push(current);
				current = s;
			} else {
				current += (current ? ' ' : '') + s;
			}
		}
		if (current) chunks.push(current);

		return chunks.length > 0 ? chunks : [text.substring(0, 500)];
	},

	_speakSentences: function(sentences, index) {
		if (index >= sentences.length || !Plugins.Tts._playing) {
			Plugins.Tts.stop();
			return;
		}

		var utterance = new SpeechSynthesisUtterance(sentences[index]);
		utterance.rate = Plugins.Tts._rate;
		utterance.lang = document.documentElement.lang || 'de-DE';

		Plugins.Tts._currentUtterance = utterance;

		// Fortschrittsanzeige aktualisieren
		var progress = document.getElementById('tts-progress');
		if (progress) {
			progress.textContent = (index + 1) + ' / ' + sentences.length;
		}

		utterance.onend = function() {
			Plugins.Tts._speakSentences(sentences, index + 1);
		};

		utterance.onerror = function() {
			Plugins.Tts.stop();
		};

		window.speechSynthesis.speak(utterance);
	},

	pause: function() {
		if (window.speechSynthesis.speaking) {
			window.speechSynthesis.pause();
			Plugins.Tts._playing = false;

			var playBtn = document.getElementById('tts-play-btn');
			if (playBtn) playBtn.textContent = 'play_arrow';
		}
	},

	resume: function() {
		window.speechSynthesis.resume();
		Plugins.Tts._playing = true;

		var playBtn = document.getElementById('tts-play-btn');
		if (playBtn) playBtn.textContent = 'pause';
	},

	stop: function() {
		window.speechSynthesis.cancel();
		Plugins.Tts._playing = false;
		Plugins.Tts._currentUtterance = null;
		Plugins.Tts._articleId = null;

		var player = document.getElementById('tts-player');
		if (player) player.remove();
	},

	togglePlay: function() {
		if (Plugins.Tts._playing) {
			Plugins.Tts.pause();
		} else {
			Plugins.Tts.resume();
		}
	},

	setRate: function(rate) {
		Plugins.Tts._rate = parseFloat(rate);

		var rateLabel = document.getElementById('tts-rate-label');
		if (rateLabel) rateLabel.textContent = Plugins.Tts._rate.toFixed(1) + 'x';
	},

	_showPlayer: function(id) {
		var existing = document.getElementById('tts-player');
		if (existing) existing.remove();

		var player = document.createElement('div');
		player.id = 'tts-player';
		player.className = 'tts-player';

		// Vorlesen-Icon
		var icon = document.createElement('i');
		icon.className = 'material-icons';
		icon.textContent = 'volume_up';
		icon.style.fontSize = '20px';
		player.appendChild(icon);

		var label = document.createElement('span');
		label.className = 'tts-player-label';
		label.textContent = 'Vorlesen';
		player.appendChild(label);

		// Fortschritt
		var progress = document.createElement('span');
		progress.id = 'tts-progress';
		progress.className = 'tts-player-progress';
		progress.textContent = '...';
		player.appendChild(progress);

		// Steuerungen
		var controls = document.createElement('div');
		controls.className = 'tts-player-controls';

		// Play/Pause
		var playBtn = document.createElement('i');
		playBtn.id = 'tts-play-btn';
		playBtn.className = 'material-icons tts-control-btn';
		playBtn.textContent = 'pause';
		playBtn.onclick = Plugins.Tts.togglePlay;
		controls.appendChild(playBtn);

		// Stop
		var stopBtn = document.createElement('i');
		stopBtn.className = 'material-icons tts-control-btn';
		stopBtn.textContent = 'stop';
		stopBtn.onclick = Plugins.Tts.stop;
		controls.appendChild(stopBtn);

		// Geschwindigkeit -
		var slowerBtn = document.createElement('i');
		slowerBtn.className = 'material-icons tts-control-btn';
		slowerBtn.textContent = 'remove';
		slowerBtn.onclick = function() {
			var r = Math.max(0.5, Plugins.Tts._rate - 0.25);
			Plugins.Tts.setRate(r);
		};
		controls.appendChild(slowerBtn);

		// Rate Label
		var rateLabel = document.createElement('span');
		rateLabel.id = 'tts-rate-label';
		rateLabel.className = 'tts-rate-label';
		rateLabel.textContent = Plugins.Tts._rate.toFixed(1) + 'x';
		controls.appendChild(rateLabel);

		// Geschwindigkeit +
		var fasterBtn = document.createElement('i');
		fasterBtn.className = 'material-icons tts-control-btn';
		fasterBtn.textContent = 'add';
		fasterBtn.onclick = function() {
			var r = Math.min(2.0, Plugins.Tts._rate + 0.25);
			Plugins.Tts.setRate(r);
		};
		controls.appendChild(fasterBtn);

		player.appendChild(controls);

		document.body.appendChild(player);
	}
};
