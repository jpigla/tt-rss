<?php

class Newsletter_BlocklistManager {

	/**
	 * Prüft ob ein Absender blockiert ist.
	 * @param string $sender_email
	 * @param int $owner_uid
	 * @return bool true = blockiert, false = erlaubt
	 */
	static function is_blocked(string $sender_email, int $owner_uid): bool {
		$pdo = Db::pdo();

		$sth = $pdo->prepare("SELECT pattern, is_block FROM ttrss_plugin_newsletter_blocklist
			WHERE owner_uid = ? ORDER BY id ASC");
		$sth->execute([$owner_uid]);

		$blocked = false;

		while ($row = $sth->fetch()) {
			if (self::matches_pattern($sender_email, $row['pattern'])) {
				$blocked = (bool)$row['is_block'];
			}
		}

		return $blocked;
	}

	/**
	 * Prüft ob eine E-Mail-Adresse zu einem Glob-Pattern passt.
	 * Unterstützt: *@domain.com, exact@email.com, *@*.ru
	 *
	 * Sicherheit: * wird kontextabhängig übersetzt:
	 * - Vor dem @: [^@]* (kein @-Zeichen, verhindert Bypass)
	 * - Nach dem @: [^@.]* (nur einzelne Domain-Labels)
	 *
	 * @param string $email
	 * @param string $pattern
	 * @return bool
	 */
	static function matches_pattern(string $email, string $pattern): bool {
		$email = strtolower(trim($email));
		$pattern = strtolower(trim($pattern));

		if (empty($pattern) || empty($email)) return false;

		// Pattern-Länge begrenzen (DoS-Schutz bei Regex)
		if (strlen($pattern) > 250) return false;

		// Pattern in lokalen Teil und Domain aufteilen
		$at_pos = strpos($pattern, '@');
		if ($at_pos !== false) {
			$local_pattern = substr($pattern, 0, $at_pos);
			$domain_pattern = substr($pattern, $at_pos + 1);

			// Lokaler Teil: * → [^@]+ (mindestens ein Zeichen, kein @)
			$local_regex = preg_quote($local_pattern, '/');
			$local_regex = str_replace('\\*', '[^@]+', $local_regex);

			// Domain-Teil: * → [^@.]+ (einzelne Domain-Labels, kein Punkt)
			$domain_regex = preg_quote($domain_pattern, '/');
			$domain_regex = str_replace('\\*', '[^@.]+', $domain_regex);

			$regex = '/^' . $local_regex . '@' . $domain_regex . '$/i';
		} else {
			// Kein @: Pattern als Substring/Domain-Match behandeln
			$regex = preg_quote($pattern, '/');
			$regex = str_replace('\\*', '[^@.]+', $regex);
			$regex = '/(?:^|@)' . $regex . '$/i';
		}

		// Regex-Kompilierung prüfen (verhindert ReDoS bei ungültigen Patterns)
		$result = @preg_match($regex, $email);
		if ($result === false) {
			Debug::log("Newsletter: Ungültiges Blocklist-Pattern: " . $pattern);
			return false;
		}

		return (bool)$result;
	}

	/**
	 * Pattern zur Blocklist hinzufügen.
	 * @param string $pattern
	 * @param bool $is_block true = blockieren, false = erlauben
	 * @param int $owner_uid
	 */
	/**
	 * Validiert ein Blocklist-Pattern.
	 * @param string $pattern
	 * @return bool
	 */
	static function is_valid_pattern(string $pattern): bool {
		$pattern = trim($pattern);
		if (empty($pattern) || strlen($pattern) > 250) return false;

		// Nur erlaubte Zeichen: alphanumerisch, @, ., -, _, *
		if (!preg_match('/^[a-zA-Z0-9@.\-_*+]+$/', $pattern)) return false;

		// Pattern muss entweder @ enthalten oder ein sinnvolles Domain-Muster sein
		if (!str_contains($pattern, '@') && !str_contains($pattern, '.') && $pattern !== '*') return false;

		return true;
	}

	static function add_pattern(string $pattern, bool $is_block, int $owner_uid): void {
		$pdo = Db::pdo();
		$pattern = strtolower(trim($pattern));

		if (!self::is_valid_pattern($pattern)) {
			Debug::log("Newsletter: Ungültiges Blocklist-Pattern abgelehnt: " . $pattern);
			return;
		}

		// Duplikat-Prüfung
		$sth = $pdo->prepare("SELECT id FROM ttrss_plugin_newsletter_blocklist
			WHERE pattern = ? AND owner_uid = ?");
		$sth->execute([$pattern, $owner_uid]);

		if ($sth->fetch()) {
			$sth = $pdo->prepare("UPDATE ttrss_plugin_newsletter_blocklist
				SET is_block = ? WHERE pattern = ? AND owner_uid = ?");
			$sth->execute([$is_block, $pattern, $owner_uid]);
		} else {
			$sth = $pdo->prepare("INSERT INTO ttrss_plugin_newsletter_blocklist
				(owner_uid, pattern, is_block) VALUES (?, ?, ?)");
			$sth->execute([$owner_uid, $pattern, $is_block]);
		}
	}

	/**
	 * Pattern aus der Blocklist entfernen.
	 * @param int $id
	 * @param int $owner_uid
	 */
	static function remove_pattern(int $id, int $owner_uid): void {
		$pdo = Db::pdo();
		$sth = $pdo->prepare("DELETE FROM ttrss_plugin_newsletter_blocklist
			WHERE id = ? AND owner_uid = ?");
		$sth->execute([$id, $owner_uid]);
	}

	/**
	 * Alle Patterns eines Benutzers laden.
	 * @param int $owner_uid
	 * @return array<array<string, mixed>>
	 */
	static function get_all(int $owner_uid): array {
		$pdo = Db::pdo();
		$sth = $pdo->prepare("SELECT * FROM ttrss_plugin_newsletter_blocklist
			WHERE owner_uid = ? ORDER BY created_at DESC");
		$sth->execute([$owner_uid]);
		return $sth->fetchAll();
	}
}
