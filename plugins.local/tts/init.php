<?php
class Tts extends Plugin {

	/** @var PluginHost $host */
	private $host;

	function about() {
		return [1.0,
			"Text-to-Speech: Artikel vorlesen lassen (Browser Web Speech API)",
			"tt-ss",
			false];
	}

	function init($host) {
		$this->host = $host;

		$host->add_hook($host::HOOK_ARTICLE_BUTTON, $this);
	}

	function get_js() {
		return file_get_contents(__DIR__ . "/tts.js");
	}

	function get_css() {
		return file_get_contents(__DIR__ . "/tts.css");
	}

	function hook_article_button($line) {
		$id = (int) $line['id'];

		return "<i class='material-icons tts-btn'
			data-article-id='" . $id . "'
			onclick=\"Plugins.Tts.speak(" . $id . ")\"
			style='cursor: pointer'
			title=\"" . __('Vorlesen') . "\">volume_up</i>";
	}

	function api_version() {
		return 2;
	}
}
