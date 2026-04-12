<?php

use ZBateson\MailMimeParser\MailMimeParser;
use ZBateson\MailMimeParser\Message as MimeMessage;

class Newsletter_EmailParser {

	private const MAX_INLINE_IMAGE_SIZE = 102400; // 100 KB

	/**
	 * E-Mail-Nachricht parsen und strukturiertes Ergebnis zurückgeben.
	 * @param string $raw_message Rohe E-Mail (RFC 822)
	 * @return array<string, mixed>
	 */
	static function parse(string $raw_message): array {
		$parser = new MailMimeParser();
		$message = $parser->parse($raw_message, false);

		$from = $message->getHeader('From');
		$sender_email = '';
		$sender_name = '';

		if ($from) {
			$from_value = $from->getRawValue();
			// "Name <email>" Format parsen
			if (preg_match('/^(.+?)\s*<([^>]+)>/', $from_value, $m)) {
				$sender_name = trim($m[1], ' "\'');
				$sender_email = strtolower(trim($m[2]));
			} elseif (preg_match('/<?([^@\s]+@[^>\s]+)>?/', $from_value, $m)) {
				$sender_email = strtolower(trim($m[1]));
			}
		}

		// E-Mail-Adresse validieren
		if ($sender_email && !filter_var($sender_email, FILTER_VALIDATE_EMAIL)) {
			$sender_email = '';
		}

		$sender_domain = '';
		if ($sender_email && str_contains($sender_email, '@')) {
			$sender_domain = substr($sender_email, strpos($sender_email, '@') + 1);
		}

		$subject = '';
		$subject_header = $message->getHeaderValue('Subject');
		if ($subject_header) {
			$subject = $subject_header;
		}

		$date = null;
		$date_header = $message->getHeader('Date');
		if ($date_header) {
			try {
				$date = new \DateTime($date_header->getRawValue());
			} catch (\Exception $e) {
				$date = new \DateTime();
			}
		} else {
			$date = new \DateTime();
		}

		$message_id = $message->getHeaderValue('Message-ID') ?? '';
		$message_id = trim($message_id, '<> ');

		// List-Unsubscribe Header parsen
		$list_unsubscribe = self::parse_list_unsubscribe($message);

		// HTML-Body extrahieren (bevorzugt), Fallback auf Text
		$html_content = self::extract_html_body($message);

		return [
			'sender_name'          => $sender_name,
			'sender_email'         => $sender_email,
			'sender_domain'        => $sender_domain,
			'subject'              => $subject,
			'date'                 => $date,
			'message_id'           => $message_id,
			'html_content'         => $html_content,
			'list_unsubscribe_url' => $list_unsubscribe,
		];
	}

	/**
	 * HTML-Body aus der Nachricht extrahieren, CID-Referenzen auflösen.
	 * @param MimeMessage $message
	 * @return string
	 */
	private static function extract_html_body(MimeMessage $message): string {
		$html = $message->getHtmlContent();

		if ($html) {
			$html = self::resolve_cid_references($message, $html);
			return $html;
		}

		$text = $message->getTextContent();
		if ($text) {
			return nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
		}

		return '';
	}

	/**
	 * CID-Referenzen in HTML durch Data-URIs oder gecachte URLs ersetzen.
	 * @param MimeMessage $message
	 * @param string $html
	 * @return string
	 */
	private static function resolve_cid_references(MimeMessage $message, string $html): string {
		// cid:xxx Referenzen finden
		if (!preg_match_all('/cid:([^"\'\s)]+)/i', $html, $matches)) {
			return $html;
		}

		$cids = array_unique($matches[1]);

		foreach ($cids as $cid) {
			$attachment = null;
			$att_count = $message->getAttachmentCount();
			for ($i = 0; $i < $att_count; $i++) {
				$att = $message->getAttachmentPart($i);
				if (!$att) continue;
				$att_cid = $att->getContentId();
				if ($att_cid && trim($att_cid, '<> ') === $cid) {
					$attachment = $att;
					break;
				}
			}

			if (!$attachment) continue;

			$content = $attachment->getContent();
			$content_type = $attachment->getContentType() ?? 'image/png';
			$size = strlen($content);

			if ($size <= self::MAX_INLINE_IMAGE_SIZE) {
				// Nur sichere Bildformate als Data-URI (kein SVG — kann JavaScript enthalten)
				$safe_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
				if (!in_array(strtolower($content_type), $safe_types, true)) {
					continue; // Unsichere Content-Types überspringen
				}

				// Kleine Bilder: Inline als Data-URI
				$data_uri = 'data:' . $content_type . ';base64,' . base64_encode($content);
				$html = str_replace('cid:' . $cid, $data_uri, $html);
			} else {
				// Große Bilder: In DiskCache speichern
				$cache_url = self::cache_inline_image($content, $content_type, $cid);
				if ($cache_url) {
					$html = str_replace('cid:' . $cid, $cache_url, $html);
				}
			}
		}

		return $html;
	}

	/**
	 * Inline-Bild im DiskCache speichern.
	 * @param string $content Bilddaten
	 * @param string $content_type MIME-Typ
	 * @param string $cid Content-ID
	 * @return string|null Öffentliche URL oder null
	 */
	private static function cache_inline_image(string $content, string $content_type, string $cid): ?string {
		try {
			$cache = DiskCache::instance("images");
			$hash = sha1($cid . $content);

			// Nur sichere Bildformate erlauben
			$ext_map = [
				'image/jpeg' => '.jpg',
				'image/png'  => '.png',
				'image/gif'  => '.gif',
				'image/webp' => '.webp',
			];

			// Content-Type muss ein erlaubtes Bildformat sein
			if (!isset($ext_map[$content_type])) {
				Debug::log("Newsletter: Nicht erlaubter Content-Type für Cache: " . $content_type);
				return null;
			}

			$ext = $ext_map[$content_type];
			// Dateiname nur aus Hex-Hash + Erweiterung (Path-Traversal-Schutz)
			$filename = $hash . $ext;
			if (!preg_match('/^[a-f0-9]+\.[a-z]+$/', $filename)) {
				return null;
			}

			if (!$cache->exists($filename)) {
				$cache->put($filename, $content);
			}

			return $cache->get_url($filename);
		} catch (\Exception $e) {
			Debug::log("Newsletter: CID-Caching fehlgeschlagen: " . $e->getMessage());
			return null;
		}
	}

	/**
	 * List-Unsubscribe Header parsen und URL extrahieren.
	 * @param MimeMessage $message
	 * @return string|null
	 */
	private static function parse_list_unsubscribe(MimeMessage $message): ?string {
		$header = $message->getHeaderValue('List-Unsubscribe');
		if (!$header) return null;

		// HTTP-URL bevorzugen
		if (preg_match('/<(https?:\/\/[^>]+)>/', $header, $m)) {
			$url = $m[1];
			// URL validieren — nur http/https erlauben, javascript: etc. blockieren
			if (filter_var($url, FILTER_VALIDATE_URL) && preg_match('/^https?:\/\//i', $url)) {
				return $url;
			}
		}

		// mailto: als Fallback — E-Mail-Adresse validieren
		if (preg_match('/<(mailto:[^>]+)>/', $header, $m)) {
			$mailto = $m[1];
			$email_part = preg_replace('/^mailto:/i', '', $mailto);
			$email_part = explode('?', $email_part)[0]; // Query-Parameter entfernen
			if (filter_var($email_part, FILTER_VALIDATE_EMAIL)) {
				return $mailto;
			}
		}

		return null;
	}
}
