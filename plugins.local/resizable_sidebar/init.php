<?php
class Resizable_Sidebar extends Plugin {

	/** @var PluginHost $host */
	private $host;

	function about() {
		return [1.0,
			"Ermöglicht das Ändern der Seitenleistenbreite per Drag & Drop",
			"tt-ss",
			false];
	}

	function init($host) {
		$this->host = $host;
	}

	function get_js() {
		return <<<'JS'
/* global require, Plugins */

require(['dojo/_base/kernel', 'dojo/ready'], function (dojo, ready) {
	ready(function () {

		Plugins.Resizable_Sidebar = {

			STORAGE_KEY: 'ttrss_sidebar_width',
			MIN_WIDTH: 150,
			MAX_WIDTH: 600,
			_dragging: false,
			_handle: null,
			_sidebar: null,

			init: function () {
				const sidebar = document.getElementById('feeds-holder');
				if (!sidebar) return;

				Plugins.Resizable_Sidebar._sidebar = sidebar;

				// Gespeicherte Breite wiederherstellen
				const savedWidth = localStorage.getItem(Plugins.Resizable_Sidebar.STORAGE_KEY);
				if (savedWidth) {
					const w = parseInt(savedWidth, 10);
					if (w >= Plugins.Resizable_Sidebar.MIN_WIDTH && w <= Plugins.Resizable_Sidebar.MAX_WIDTH) {
						sidebar.style.width = w + 'px';
						sidebar.style.minWidth = w + 'px';
						sidebar.style.maxWidth = w + 'px';
					}
				}

				// Drag-Handle erstellen
				const handle = document.createElement('div');
				handle.className = 'rs-drag-handle';
				sidebar.style.position = 'relative';
				sidebar.appendChild(handle);

				Plugins.Resizable_Sidebar._handle = handle;

				handle.addEventListener('mousedown', function (e) {
					e.preventDefault();
					Plugins.Resizable_Sidebar._dragging = true;
					document.body.style.cursor = 'col-resize';
					document.body.style.userSelect = 'none';
				});

				document.addEventListener('mousemove', function (e) {
					if (!Plugins.Resizable_Sidebar._dragging) return;

					const rect = sidebar.getBoundingClientRect();
					let newWidth = e.clientX - rect.left;

					newWidth = Math.max(Plugins.Resizable_Sidebar.MIN_WIDTH,
						Math.min(Plugins.Resizable_Sidebar.MAX_WIDTH, newWidth));

					sidebar.style.width = newWidth + 'px';
					sidebar.style.minWidth = newWidth + 'px';
					sidebar.style.maxWidth = newWidth + 'px';
				});

				document.addEventListener('mouseup', function () {
					if (!Plugins.Resizable_Sidebar._dragging) return;
					Plugins.Resizable_Sidebar._dragging = false;
					document.body.style.cursor = '';
					document.body.style.userSelect = '';

					const currentWidth = parseInt(sidebar.style.width, 10);
					if (currentWidth) {
						localStorage.setItem(Plugins.Resizable_Sidebar.STORAGE_KEY, currentWidth);
					}
				});
			}
		};

		Plugins.Resizable_Sidebar.init();
	});
});
JS;
	}

	function get_css() {
		return <<<'CSS'
.rs-drag-handle {
	position: absolute;
	top: 0;
	right: -2px;
	width: 5px;
	height: 100%;
	cursor: col-resize;
	z-index: 100;
	background: transparent;
	transition: background-color 0.15s;
}

.rs-drag-handle:hover {
	background-color: var(--color-accent, #257fad);
	opacity: 0.5;
}
CSS;
	}

	function api_version() {
		return 2;
	}
}
