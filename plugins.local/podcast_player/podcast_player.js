Plugins.PodcastPlayer = {
	saveInterval: null,

	init: function(audioEl) {
		const url = audioEl.getAttribute('data-enclosure-url');
		if (!url) return;

		// Gespeicherte Position laden
		xhr.json("backend.php", {
			op: "pluginhandler",
			plugin: "podcast_player",
			method: "get_progress",
			url: url
		}, (reply) => {
			if (reply && reply.position > 0 && !reply.completed) {
				audioEl.currentTime = reply.position;
			}
		});

		// Position regelmäßig speichern
		audioEl.addEventListener('play', () => {
			Plugins.PodcastPlayer.saveInterval = setInterval(() => {
				Plugins.PodcastPlayer.savePosition(audioEl);
			}, 10000);
		});

		audioEl.addEventListener('pause', () => {
			clearInterval(Plugins.PodcastPlayer.saveInterval);
			Plugins.PodcastPlayer.savePosition(audioEl);
		});

		audioEl.addEventListener('ended', () => {
			clearInterval(Plugins.PodcastPlayer.saveInterval);
			Plugins.PodcastPlayer.markCompleted(audioEl);
		});
	},

	savePosition: function(audioEl) {
		const url = audioEl.getAttribute('data-enclosure-url');
		if (!url || audioEl.paused) return;

		xhr.json("backend.php", {
			op: "pluginhandler",
			plugin: "podcast_player",
			method: "save_progress",
			url: url,
			position: Math.floor(audioEl.currentTime)
		});
	},

	markCompleted: function(audioEl) {
		const url = audioEl.getAttribute('data-enclosure-url');
		if (!url) return;

		xhr.json("backend.php", {
			op: "pluginhandler",
			plugin: "podcast_player",
			method: "save_progress",
			url: url,
			position: 0,
			completed: 1
		});
	},

	setSpeed: function(audioEl, speed) {
		audioEl.playbackRate = speed;

		const container = audioEl.closest('.podcast-player');
		if (container) {
			container.querySelectorAll('.speed-btn').forEach(btn => {
				btn.classList.toggle('active', parseFloat(btn.dataset.speed) === speed);
			});
		}
	},

	skip: function(audioEl, seconds) {
		audioEl.currentTime = Math.max(0, audioEl.currentTime + seconds);
	}
};

// Alle Podcast-Player initialisieren, wenn der DOM geladen ist
document.addEventListener('DOMContentLoaded', () => {
	document.querySelectorAll('audio.podcast-audio').forEach(el => {
		Plugins.PodcastPlayer.init(el);
	});
});
