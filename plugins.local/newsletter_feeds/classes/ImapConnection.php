<?php

class Newsletter_ImapConnection {

	private const CONNECT_TIMEOUT = 30;
	private const FETCH_LIMIT = 50;

	/**
	 * IMAP-Client erstellen und verbinden.
	 * @param array<string, mixed> $inbox Zeile aus ttrss_plugin_newsletter_inboxes
	 * @return \Webklex\PHPIMAP\Client
	 * @throws \Exception
	 */
	static function connect(array $inbox): \Webklex\PHPIMAP\Client {
		// SSRF-Schutz: IMAP-Server validieren
		$host = $inbox['imap_server'] ?? '';
		if (!self::is_valid_imap_host($host)) {
			throw new \Exception("Ungültiger IMAP-Server: " . $host);
		}

		$port = (int)($inbox['imap_port'] ?? 993);
		if ($port < 1 || $port > 65535) {
			throw new \Exception("Ungültiger IMAP-Port: " . $port);
		}

		$password = self::decrypt_password($inbox);

		$client = new \Webklex\PHPIMAP\Client([
			'host'          => $host,
			'port'          => $port,
			'encryption'    => 'ssl',
			'validate_cert' => true,
			'username'      => $inbox['imap_user'],
			'password'      => $password,
			'protocol'      => 'imap',
			'timeout'       => self::CONNECT_TIMEOUT,
		]);

		$client->connect();
		return $client;
	}

	/**
	 * Prüft ob ein IMAP-Host gültig und sicher ist (SSRF-Schutz).
	 * Blockiert: localhost, private IPs, Sonderzeichen.
	 */
	static function is_valid_imap_host(string $host): bool {
		if (empty($host)) return false;

		// Nur gültige Hostnamen erlauben (alphanumerisch, Punkte, Bindestriche)
		if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]*[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9\-]*[a-zA-Z0-9])?)*$/', $host)) {
			return false;
		}

		// Mindestens ein Punkt (keine lokalen Hostnamen)
		if (!str_contains($host, '.')) return false;

		// Private/lokale Hostnamen blockieren
		$blocked_patterns = ['localhost', '127.0.0.1', '0.0.0.0', '::1', '.local', '.internal', '.localhost'];
		$host_lower = strtolower($host);
		foreach ($blocked_patterns as $pattern) {
			if ($host_lower === $pattern || str_ends_with($host_lower, $pattern)) {
				return false;
			}
		}

		// DNS-Auflösung prüfen: private IP-Bereiche blockieren
		$ips = gethostbynamel($host);
		if ($ips) {
			foreach ($ips as $ip) {
				if (self::is_private_ip($ip)) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Prüft ob eine IP-Adresse in einem privaten Bereich liegt.
	 */
	private static function is_private_ip(string $ip): bool {
		return !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
	}

	/**
	 * Ungelesene Nachrichten seit einem Datum abrufen.
	 * @param \Webklex\PHPIMAP\Client $client
	 * @param string $folder IMAP-Ordner
	 * @param string|null $since Datum (Y-m-d) oder null für gestern
	 * @param int $limit Max Nachrichten pro Abruf
	 * @return \Webklex\PHPIMAP\Support\MessageCollection
	 */
	static function fetchUnseenSince(
		\Webklex\PHPIMAP\Client $client,
		string $folder = 'INBOX',
		?string $since = null,
		int $limit = self::FETCH_LIMIT
	): \Webklex\PHPIMAP\Support\MessageCollection {
		$folder_obj = $client->getFolder($folder);

		if (!$folder_obj) {
			throw new \Exception("IMAP-Ordner '{$folder}' nicht gefunden");
		}

		if (!$since) {
			$since = date('Y-m-d', strtotime('-1 day'));
		}

		$query = $folder_obj->query()
			->unseen()
			->since($since)
			->limit($limit);

		return $query->get();
	}

	/**
	 * Nachricht als gelesen markieren.
	 * @param \Webklex\PHPIMAP\Message $message
	 */
	static function markSeen(\Webklex\PHPIMAP\Message $message): void {
		$message->setFlag('Seen');
	}

	/**
	 * Verbindung testen und Nachrichtenanzahl zurückgeben.
	 * @param array<string, mixed> $inbox
	 * @return array{success: bool, message: string}
	 */
	static function testConnection(array $inbox): array {
		try {
			$client = self::connect($inbox);
			$folder = $client->getFolder($inbox['imap_folder'] ?: 'INBOX');

			if (!$folder) {
				$client->disconnect();
				return [
					'success' => false,
					'message' => __('Der angegebene IMAP-Ordner wurde nicht gefunden.'),
				];
			}

			$status = $folder->examine();
			$count = $status['exists'] ?? 0;
			$client->disconnect();

			return [
				'success' => true,
				'message' => sprintf(__('Verbindung erfolgreich. %d Nachrichten im Postfach.'), $count),
			];
		} catch (\Exception $e) {
			// Keine detaillierten Fehlermeldungen an den Benutzer (verhindert Info-Leaking)
			Debug::log("Newsletter: IMAP-Verbindungstest fehlgeschlagen: " . $e->getMessage());
			return [
				'success' => false,
				'message' => __('Verbindung fehlgeschlagen. Bitte Server, Port und Zugangsdaten prüfen.'),
			];
		}
	}

	/**
	 * Passwort entschlüsseln (verschlüsselt bevorzugt, Fallback auf Klartext).
	 * @param array<string, mixed> $inbox
	 * @return string
	 */
	private static function decrypt_password(array $inbox): string {
		if (!empty($inbox['imap_pass_encrypted'])) {
			try {
				$decoded = base64_decode($inbox['imap_pass_encrypted'], true);
				if ($decoded === false) {
					throw new \Exception("Ungültige Base64-Kodierung");
				}

				// Sichere JSON-Dekodierung statt unserialize() (CWE-502)
				$encrypted = json_decode($decoded, true, 4, JSON_THROW_ON_ERROR);

				if (!is_array($encrypted)
					|| !isset($encrypted['algo'], $encrypted['nonce'], $encrypted['payload'])
					|| !is_string($encrypted['algo'])
					|| !is_string($encrypted['nonce'])
					|| !is_string($encrypted['payload'])) {
					throw new \Exception("Ungültiges verschlüsseltes Datenobjekt");
				}

				// Binärdaten aus Base64 zurückwandeln (JSON-sicheres Format)
				$encrypted['nonce'] = base64_decode($encrypted['nonce'], true);
				$encrypted['payload'] = base64_decode($encrypted['payload'], true);

				if ($encrypted['nonce'] === false || $encrypted['payload'] === false) {
					throw new \Exception("Ungültige Binärdaten im verschlüsselten Objekt");
				}

				return Crypt::decrypt_string($encrypted);
			} catch (\Exception $e) {
				Debug::log("Newsletter: Passwort-Entschlüsselung fehlgeschlagen: " . $e->getMessage());
			}
		}

		// Fallback auf unverschlüsseltes Passwort (Altdaten)
		return $inbox['imap_pass'] ?? '';
	}

	/**
	 * Passwort verschlüsseln für Speicherung.
	 * Verwendet JSON statt serialize() um Deserialisierungs-Angriffe zu verhindern (CWE-502).
	 * @param string $password
	 * @return string Base64-kodiertes JSON-verschlüsseltes Objekt
	 */
	static function encrypt_password(string $password): string {
		$encrypted = Crypt::encrypt_string($password);

		// Binärdaten Base64-kodieren für JSON-Kompatibilität
		$json_safe = [
			'algo'    => $encrypted['algo'],
			'nonce'   => base64_encode($encrypted['nonce']),
			'payload' => base64_encode($encrypted['payload']),
		];

		return base64_encode(json_encode($json_safe, JSON_THROW_ON_ERROR));
	}

	/**
	 * Legacy-Format (serialize) lesen und ins neue JSON-Format migrieren.
	 * @param string $encrypted_data Base64-kodierte Daten (serialize oder JSON)
	 * @return array{algo: string, nonce: string, payload: string}|null
	 */
	static function decode_legacy_encrypted(string $encrypted_data): ?array {
		$decoded = base64_decode($encrypted_data, true);
		if ($decoded === false) return null;

		// Zuerst JSON probieren (neues Format)
		$json = json_decode($decoded, true);
		if (is_array($json) && isset($json['algo'], $json['nonce'], $json['payload'])) {
			return [
				'algo'    => $json['algo'],
				'nonce'   => base64_decode($json['nonce'], true) ?: '',
				'payload' => base64_decode($json['payload'], true) ?: '',
			];
		}

		// Fallback: serialize-Format (nur mit allowed_classes: false für Sicherheit)
		$unserialized = @unserialize($decoded, ['allowed_classes' => false]);
		if (is_array($unserialized) && isset($unserialized['algo'], $unserialized['nonce'], $unserialized['payload'])) {
			return $unserialized;
		}

		return null;
	}
}
