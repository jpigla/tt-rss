<?php
class Mobile_Pwa extends Plugin {

	/** @var PluginHost $host */
	private $host;

	function about() {
		return [1.0,
			"Mobile PWA-Optimierung — Touch-UI, Safe Area, Swipe-Navigation",
			"tt-ss",
			false];
	}

	function init($host) {
		$this->host = $host;
	}

	function get_css() {
		return file_get_contents(__DIR__ . "/mobile_pwa.css");
	}

	function get_js() {
		return file_get_contents(__DIR__ . "/mobile_pwa.js");
	}

	function api_version() {
		return 2;
	}
}
