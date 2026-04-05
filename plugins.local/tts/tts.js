/* global Plugins, Notify, App, xhr */

Plugins.Tts = {
	_currentUtterance: null,
	_audioEl: null,
	_rate: 1.0,
	_playing: false,
	_articleId: null,
	_service: null, // wird beim ersten Aufruf geladen

	speak: function (id) {
		// Service-Typ aus dem Button-data-Attribut lesen
		var btn = document.querySelector('.tts-btn[data-article-id="' + id + '"]');
		var service = btn ? btn.getAttribute('data-tts-service') : 'browser';

		// Falls gerade derselbe Artikel läuft -> Pause/Resume
		if (Plugins.Tts._articleId === id && Plugins.Tts._playing) {
			Plugins.Tts.pause();
			return;
		}

		// Laufende Wiedergabe stoppen
		Plugins.Tts.stop();

		if (service === 'server') {
			Plugins.Tts._speakServer(id);
		} else {
			Plugins.Tts._speakBrowser(id);
		}
	},

	// ── Server-basiertes TTS (edge-tts / OpenAI) ──────────────────────

	_speakServer: function (id) {
		Plugins.Tts._articleId = id;
		Plugins.Tts._playing = true;
		Plugins.Tts._showPlayer(id, 'server');

		var audioUrl = 'backend.php?op=pluginhandler&plugin=tts&method=stream_audio'
			+ '&article_id=' + id;

		var audio = new Audio(audioUrl);
		audio.playbackRate = Plugins.Tts._rate;
		Plugins.Tts._audioEl = audio;

		var progress = document.getElementById('tts-progress');

		var hasStarted = false;

		audio.addEventListener('loadstart', function () {
			if (progress) progress.textContent = 'Lädt...';
		});

		audio.addEventListener('canplay', function () {
			hasStarted = true;
			if (progress) progress.textContent = 'Wiedergabe';
		});

		audio.addEventListener('timeupdate', function () {
			hasStarted = true;
			if (progress && audio.duration) {
				var cur = Plugins.Tts._formatTime(audio.currentTime);
				var dur = Plugins.Tts._formatTime(audio.duration);
				progress.textContent = cur + ' / ' + dur;
			}
		});

		audio.addEventListener('ended', function () {
			Plugins.Tts.stop();
		});

		audio.addEventListener('error', function () {
			// Fehler nur anzeigen wenn das Audio noch nicht angefangen hat zu spielen
			if (!hasStarted) {
				Notify.error('TTS-Server-Fehler. Endpunkt erreichbar?');
				Plugins.Tts.stop();
			}
		});

		audio.play().catch(function () {
			if (!hasStarted) {
				Notify.error('Audio-Wiedergabe fehlgeschlagen.');
				Plugins.Tts.stop();
			}
		});
	},

	_formatTime: function (sec) {
		var m = Math.floor(sec / 60);
		var s = Math.floor(sec % 60);
		return m + ':' + (s < 10 ? '0' : '') + s;
	},

	// ── Browser-basiertes TTS (Web Speech API) ────────────────────────

	_speakBrowser: function (id) {
		if (!window.speechSynthesis) {
			Notify.error('Text-to-Speech wird von diesem Browser nicht unterstützt.');
			return;
		}

		window.speechSynthesis.cancel();

		var contentEl = document.querySelector(
			'#RROW-' + id + ' .content-inner'
		) || document.querySelector('#content-insert .post .content');

		if (!contentEl) {
			Notify.error('Artikelinhalt nicht gefunden.');
			return;
		}

		var text = (contentEl.textContent || contentEl.innerText).trim();
		if (!text) {
			Notify.error('Kein Text zum Vorlesen gefunden.');
			return;
		}

		var sentences = Plugins.Tts._splitText(text);

		Plugins.Tts._articleId = id;
		Plugins.Tts._playing = true;
		Plugins.Tts._showPlayer(id, 'browser');

		Plugins.Tts._speakSentences(sentences, 0);
	},

	_splitText: function (text) {
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

	_speakSentences: function (sentences, index) {
		if (index >= sentences.length || !Plugins.Tts._playing) {
			Plugins.Tts.stop();
			return;
		}

		var utterance = new SpeechSynthesisUtterance(sentences[index]);
		utterance.rate = Plugins.Tts._rate;
		utterance.lang = document.documentElement.lang || 'de-DE';

		Plugins.Tts._currentUtterance = utterance;

		var progress = document.getElementById('tts-progress');
		if (progress) {
			progress.textContent = (index + 1) + ' / ' + sentences.length;
		}

		utterance.onend = function () {
			Plugins.Tts._speakSentences(sentences, index + 1);
		};

		utterance.onerror = function () {
			Plugins.Tts.stop();
		};

		window.speechSynthesis.speak(utterance);
	},

	// ── Steuerung ─────────────────────────────────────────────────────

	pause: function () {
		if (Plugins.Tts._audioEl) {
			Plugins.Tts._audioEl.pause();
		} else if (window.speechSynthesis && window.speechSynthesis.speaking) {
			window.speechSynthesis.pause();
		}

		Plugins.Tts._playing = false;
		var playBtn = document.getElementById('tts-play-btn');
		if (playBtn) playBtn.textContent = 'play_arrow';
	},

	resume: function () {
		if (Plugins.Tts._audioEl) {
			Plugins.Tts._audioEl.play();
		} else if (window.speechSynthesis) {
			window.speechSynthesis.resume();
		}

		Plugins.Tts._playing = true;
		var playBtn = document.getElementById('tts-play-btn');
		if (playBtn) playBtn.textContent = 'pause';
	},

	stop: function () {
		if (Plugins.Tts._audioEl) {
			Plugins.Tts._audioEl.pause();
			Plugins.Tts._audioEl.src = '';
			Plugins.Tts._audioEl = null;
		}

		if (window.speechSynthesis) {
			window.speechSynthesis.cancel();
		}

		Plugins.Tts._playing = false;
		Plugins.Tts._currentUtterance = null;
		Plugins.Tts._articleId = null;

		var player = document.getElementById('tts-player');
		if (player) player.remove();
	},

	togglePlay: function () {
		if (Plugins.Tts._playing) {
			Plugins.Tts.pause();
		} else {
			Plugins.Tts.resume();
		}
	},

	setRate: function (rate) {
		Plugins.Tts._rate = parseFloat(rate);

		// Audio-Element: Rate sofort ändern
		if (Plugins.Tts._audioEl) {
			Plugins.Tts._audioEl.playbackRate = Plugins.Tts._rate;
		}

		var rateLabel = document.getElementById('tts-rate-label');
		if (rateLabel) rateLabel.textContent = Plugins.Tts._rate.toFixed(1) + 'x';
	},

	// ── Player-UI ─────────────────────────────────────────────────────

	_showPlayer: function (id, mode) {
		var existing = document.getElementById('tts-player');
		if (existing) existing.remove();

		var player = document.createElement('div');
		player.id = 'tts-player';
		player.className = 'tts-player';

		// Icon
		var icon = document.createElement('i');
		icon.className = 'material-icons';
		icon.textContent = 'volume_up';
		icon.style.fontSize = '20px';
		player.appendChild(icon);

		// Label
		var label = document.createElement('span');
		label.className = 'tts-player-label';
		label.textContent = mode === 'server' ? 'Edge TTS' : 'Vorlesen';
		player.appendChild(label);

		// Fortschritt
		var progress = document.createElement('span');
		progress.id = 'tts-progress';
		progress.className = 'tts-player-progress';
		progress.textContent = mode === 'server' ? 'Lädt...' : '...';
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
		slowerBtn.onclick = function () {
			Plugins.Tts.setRate(Math.max(0.5, Plugins.Tts._rate - 0.25));
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
		fasterBtn.onclick = function () {
			Plugins.Tts.setRate(Math.min(3.0, Plugins.Tts._rate + 0.25));
		};
		controls.appendChild(fasterBtn);

		player.appendChild(controls);
		document.body.appendChild(player);
	}
};
