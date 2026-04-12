/* global require, App, Feeds */

/**
 * TT-SS Mobile PWA — Sidebar-Toggle, Swipe-Navigation, Pull-to-Refresh.
 */
require(['dojo/_base/kernel', 'dojo/ready'], function (dojo, ready) {
	ready(function () {
		if (typeof App === 'undefined') return;

		var isMobile = window.matchMedia('(max-width: 768px)').matches;

		// ─── Hamburger-Button erstellen ─────────────────────

		var menuBtn = document.createElement('button');
		menuBtn.className = 'mobile-menu-btn';
		menuBtn.type = 'button';
		menuBtn.title = 'Menü';

		var menuIcon = document.createElement('i');
		menuIcon.className = 'material-icons';
		menuIcon.textContent = 'menu';
		menuBtn.appendChild(menuIcon);

		var toolbar = document.getElementById('toolbar');
		if (toolbar) {
			toolbar.insertBefore(menuBtn, toolbar.firstChild);
		}

		// ─── Sidebar-Overlay ────────────────────────────────

		var overlay = document.createElement('div');
		overlay.className = 'mobile-sidebar-overlay';
		document.body.appendChild(overlay);

		var feedsHolder = document.getElementById('feeds-holder');

		function toggleSidebar() {
			if (!feedsHolder) return;
			var isVisible = feedsHolder.classList.contains('mobile-visible');
			feedsHolder.classList.toggle('mobile-visible', !isVisible);
			overlay.classList.toggle('visible', !isVisible);
		}

		function closeSidebar() {
			if (!feedsHolder) return;
			feedsHolder.classList.remove('mobile-visible');
			overlay.classList.remove('visible');
		}

		menuBtn.addEventListener('click', toggleSidebar);
		overlay.addEventListener('click', closeSidebar);

		// Feed-Auswahl schließt Sidebar auf Mobil
		if (feedsHolder) {
			feedsHolder.addEventListener('click', function (e) {
				if (!window.matchMedia('(max-width: 768px)').matches) return;
				var treeRow = e.target.closest('.dijitTreeRow');
				if (treeRow) {
					setTimeout(closeSidebar, 150);
				}
			});
		}

		// ─── Refresh-Indikator ──────────────────────────────

		var refreshIndicator = document.createElement('div');
		refreshIndicator.className = 'mobile-refresh-indicator';
		document.body.appendChild(refreshIndicator);

		// ─── Swipe-Navigation ───────────────────────────────

		var touchStartX = 0;
		var touchStartY = 0;
		var touchStartTime = 0;
		var swiping = false;

		document.addEventListener('touchstart', function (e) {
			touchStartX = e.touches[0].clientX;
			touchStartY = e.touches[0].clientY;
			touchStartTime = Date.now();
			swiping = true;
		}, { passive: true });

		document.addEventListener('touchend', function (e) {
			if (!swiping) return;
			swiping = false;

			var dx = e.changedTouches[0].clientX - touchStartX;
			var dy = e.changedTouches[0].clientY - touchStartY;
			var dt = Date.now() - touchStartTime;

			// Mindest-Swipe: 60px horizontal, weniger als 100px vertikal, unter 400ms
			if (Math.abs(dx) < 60 || Math.abs(dy) > 100 || dt > 400) return;

			var mobile = window.matchMedia('(max-width: 768px)').matches;

			if (dx > 0 && touchStartX < 30) {
				// Swipe rechts vom linken Rand → Sidebar öffnen
				if (mobile && feedsHolder && !feedsHolder.classList.contains('mobile-visible')) {
					toggleSidebar();
				}
			} else if (dx < 0) {
				// Swipe links → Sidebar schließen
				if (mobile && feedsHolder && feedsHolder.classList.contains('mobile-visible')) {
					closeSidebar();
				}
			}
		}, { passive: true });

		// ─── Keyboard: Escape schließt Sidebar ──────────────

		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') {
				closeSidebar();
			}
		});

		// ─── Resize-Handler ─────────────────────────────────

		var resizeTimeout = null;
		window.addEventListener('resize', function () {
			clearTimeout(resizeTimeout);
			resizeTimeout = setTimeout(function () {
				if (!window.matchMedia('(max-width: 768px)').matches) {
					closeSidebar();
					// Feeds-Holder Reset
					if (feedsHolder) {
						feedsHolder.classList.remove('mobile-visible');
					}
				}
			}, 200);
		});
	});
});
