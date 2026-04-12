<?php

class Newsletter_Article {

	/**
	 * Artikel aus E-Mail als echten Feed-Eintrag erstellen.
	 * @param array<string, mixed> $email_data Geparstes E-Mail-Ergebnis aus EmailParser
	 * @param int $feed_id Newsletter-Feed-ID
	 * @param array<string, mixed> $subscription Subscription-Daten
	 * @param int $inbox_id Postfach-ID (für Duplikat-Tracking)
	 * @return bool true wenn Artikel erstellt wurde
	 */
	static function create(array $email_data, int $feed_id, array $subscription, int $inbox_id): bool {
		$pdo = Db::pdo();
		$owner_uid = (int)$subscription['owner_uid'];
		$message_id = $email_data['message_id'];

		// Duplikat-Prüfung via Message-ID
		if (!empty($message_id) && self::isProcessed($inbox_id, $message_id)) {
			return false;
		}

		$title = $email_data['subject'] ?: __('(Kein Betreff)');
		$content = $email_data['html_content'];
		$author = $email_data['sender_name'] ?: $email_data['sender_email'];
		$link = 'mailto:' . $email_data['sender_email'];

		/** @var \DateTime $date */
		$date = $email_data['date'];
		$date_str = $date->format('Y-m-d H:i:s');

		// GUID generieren — eindeutig pro Message-ID
		$guid_source = $message_id ?: ($email_data['sender_email'] . ':' . $title . ':' . $date_str);
		$guid = 'SHA1:newsletter:' . sha1($guid_source);

		$content_hash = sha1($content);

		$pdo->beginTransaction();

		try {
			// Prüfen ob Entry mit dieser GUID bereits existiert
			$sth = $pdo->prepare("SELECT id FROM ttrss_entries WHERE guid = ?");
			$sth->execute([$guid]);
			$existing = $sth->fetch();

			if ($existing) {
				$ref_id = (int)$existing['id'];

				// Prüfen ob User-Entry bereits existiert
				$sth = $pdo->prepare("SELECT int_id FROM ttrss_user_entries
					WHERE ref_id = ? AND owner_uid = ?");
				$sth->execute([$ref_id, $owner_uid]);

				if ($sth->fetch()) {
					$pdo->commit();
					self::markProcessed($inbox_id, $message_id);
					return false; // Bereits vorhanden
				}

				// User-Entry erstellen für existierende Entry
				self::insertUserEntry($pdo, $ref_id, $feed_id, $owner_uid);
			} else {
				// Neue Entry erstellen
				$sth = $pdo->prepare("INSERT INTO ttrss_entries
					(title, guid, link, updated, content, content_hash, date_entered, date_updated, author)
					VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW(), ?)
					RETURNING id");
				$sth->execute([$title, $guid, $link, $date_str, $content, $content_hash, $author]);
				$row = $sth->fetch();
				$ref_id = (int)$row['id'];

				// Fulltext-Index aktualisieren
				self::updateFulltextIndex($pdo, $ref_id, $content);

				// User-Entry erstellen
				self::insertUserEntry($pdo, $ref_id, $feed_id, $owner_uid);
			}

			// Auto-Labels anwenden
			self::applyAutoLabels($ref_id, $subscription, $owner_uid);

			// Duplikat-Tracking
			if (!empty($message_id)) {
				self::markProcessed($inbox_id, $message_id);
			}

			// Subscription-Statistiken aktualisieren
			Newsletter_SenderManager::updateStats((int)$subscription['id']);

			$pdo->commit();
			return true;

		} catch (\Exception $e) {
			$pdo->rollBack();
			Debug::log("Newsletter: Artikel-Erstellung fehlgeschlagen: " . $e->getMessage());
			return false;
		}
	}

	/**
	 * User-Entry als ungelesenen Feed-Artikel einfügen.
	 */
	private static function insertUserEntry(\PDO $pdo, int $ref_id, int $feed_id, int $owner_uid): void {
		$sth = $pdo->prepare("INSERT INTO ttrss_user_entries
			(ref_id, uuid, feed_id, orig_feed_id, owner_uid, published, tag_cache,
			 label_cache, last_read, note, unread, marked, score)
			VALUES
			(?, '', ?, NULL, ?, false, '', '', NULL, '', true, false, 0)");
		$sth->execute([$ref_id, $feed_id, $owner_uid]);
	}

	/**
	 * Fulltext-Index für Volltextsuche aktualisieren.
	 */
	private static function updateFulltextIndex(\PDO $pdo, int $ref_id, string $content): void {
		try {
			$text = mb_substr(
				\Soundasleep\Html2Text::convert($content),
				0, 900000
			);
			$sth = $pdo->prepare("UPDATE ttrss_entries
				SET tsvector_combined = to_tsvector(:ts_content)
				WHERE id = :id");
			$sth->execute([':ts_content' => $text, ':id' => $ref_id]);
		} catch (\Exception $e) {
			Debug::log("Newsletter: Fulltext-Index-Update fehlgeschlagen: " . $e->getMessage());
		}
	}

	/**
	 * Auto-Labels aus der Subscription auf den Artikel anwenden.
	 */
	private static function applyAutoLabels(int $ref_id, array $subscription, int $owner_uid): void {
		$labels_str = $subscription['auto_labels'] ?? '';
		if (empty($labels_str)) return;

		$labels = array_map('trim', explode(',', $labels_str));
		foreach ($labels as $label) {
			if (!empty($label)) {
				Labels::add_article($ref_id, $label, $owner_uid);
			}
		}
	}

	/**
	 * Prüft ob eine Message-ID bereits verarbeitet wurde.
	 */
	private static function isProcessed(int $inbox_id, string $message_id): bool {
		if (empty($message_id)) return false;

		$pdo = Db::pdo();
		$sth = $pdo->prepare("SELECT id FROM ttrss_plugin_newsletter_processed
			WHERE inbox_id = ? AND message_id = ?");
		$sth->execute([$inbox_id, $message_id]);
		return (bool)$sth->fetch();
	}

	/**
	 * Message-ID als verarbeitet markieren.
	 */
	private static function markProcessed(int $inbox_id, string $message_id): void {
		if (empty($message_id)) return;

		$pdo = Db::pdo();
		$sth = $pdo->prepare("INSERT INTO ttrss_plugin_newsletter_processed
			(inbox_id, message_id) VALUES (?, ?)
			ON CONFLICT (inbox_id, message_id) DO NOTHING");
		$sth->execute([$inbox_id, $message_id]);
	}

	/**
	 * Alte Processed-Einträge aufräumen (> 90 Tage).
	 */
	static function cleanupProcessed(): void {
		$pdo = Db::pdo();
		$sth = $pdo->prepare("DELETE FROM ttrss_plugin_newsletter_processed
			WHERE processed_at < NOW() - INTERVAL '90 days'");
		$sth->execute();
	}
}
