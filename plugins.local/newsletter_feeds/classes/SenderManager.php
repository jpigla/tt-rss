<?php

class Newsletter_SenderManager {

	private const FEED_URL_PREFIX = 'newsletter://';

	/**
	 * Feed für einen Absender holen oder neu erstellen.
	 * @param array<string, mixed> $email_data Geparstes E-Mail-Ergebnis aus EmailParser
	 * @param array<string, mixed> $inbox Postfach-Konfiguration
	 * @return array{feed_id: int, subscription: array<string, mixed>}|null null wenn blockiert
	 */
	static function getOrCreateFeed(array $email_data, array $inbox): ?array {
		$owner_uid = (int)$inbox['owner_uid'];
		$sender_email = $email_data['sender_email'];

		if (empty($sender_email)) return null;

		// Blocklist prüfen
		if (Newsletter_BlocklistManager::is_blocked($sender_email, $owner_uid)) {
			return null;
		}

		$pdo = Db::pdo();

		// Bestehende Subscription suchen
		$sth = $pdo->prepare("SELECT ns.*, f.id AS f_id FROM ttrss_plugin_newsletter_subscriptions ns
			JOIN ttrss_feeds f ON f.id = ns.feed_id
			WHERE ns.sender_email = ? AND ns.owner_uid = ?");
		$sth->execute([$sender_email, $owner_uid]);

		$sub = $sth->fetch();
		if ($sub) {
			// List-Unsubscribe aktualisieren falls vorhanden
			if ($email_data['list_unsubscribe_url'] && $email_data['list_unsubscribe_url'] !== $sub['list_unsubscribe']) {
				$upd = $pdo->prepare("UPDATE ttrss_plugin_newsletter_subscriptions
					SET list_unsubscribe = ? WHERE id = ?");
				$upd->execute([$email_data['list_unsubscribe_url'], $sub['id']]);
			}

			return [
				'feed_id' => (int)$sub['feed_id'],
				'subscription' => $sub,
			];
		}

		// Neuen Feed + Subscription erstellen
		return self::createFeedAndSubscription($email_data, $inbox);
	}

	/**
	 * Neuen Feed in ttrss_feeds und Subscription erstellen.
	 * @param array<string, mixed> $email_data
	 * @param array<string, mixed> $inbox
	 * @return array{feed_id: int, subscription: array<string, mixed>}
	 */
	private static function createFeedAndSubscription(array $email_data, array $inbox): array {
		$pdo = Db::pdo();
		$owner_uid = (int)$inbox['owner_uid'];
		$sender_email = $email_data['sender_email'];
		$sender_name = $email_data['sender_name'] ?: $sender_email;
		$sender_domain = $email_data['sender_domain'];
		$feed_url = self::FEED_URL_PREFIX . $sender_email;
		$site_url = $sender_domain ? ('https://' . $sender_domain) : '';
		$cat_id = $inbox['default_cat_id'] ?: null;

		$pdo->beginTransaction();

		try {
			// Feed erstellen — update_interval = -1 verhindert RSS-Updates
			$sth = $pdo->prepare("INSERT INTO ttrss_feeds
				(owner_uid, feed_url, title, cat_id, site_url, update_interval, update_method)
				VALUES (?, ?, ?, ?, ?, -1, 0)
				ON CONFLICT (feed_url, owner_uid) DO UPDATE SET title = EXCLUDED.title
				RETURNING id");
			$sth->execute([$owner_uid, $feed_url, $sender_name, $cat_id, $site_url]);
			$feed_row = $sth->fetch();
			$feed_id = (int)$feed_row['id'];

			// Subscription erstellen
			$sth = $pdo->prepare("INSERT INTO ttrss_plugin_newsletter_subscriptions
				(inbox_id, owner_uid, feed_id, sender_email, sender_name, sender_domain, list_unsubscribe)
				VALUES (?, ?, ?, ?, ?, ?, ?)
				ON CONFLICT (owner_uid, sender_email) DO UPDATE
				SET sender_name = EXCLUDED.sender_name, list_unsubscribe = EXCLUDED.list_unsubscribe
				RETURNING *");
			$sth->execute([
				(int)$inbox['id'], $owner_uid, $feed_id,
				$sender_email, $email_data['sender_name'] ?? '',
				$sender_domain, $email_data['list_unsubscribe_url']
			]);
			$subscription = $sth->fetch();

			$pdo->commit();

			Debug::log("Newsletter: Neuer Feed erstellt für {$sender_email} (Feed-ID: {$feed_id})");

			return [
				'feed_id' => $feed_id,
				'subscription' => $subscription,
			];
		} catch (\Exception $e) {
			$pdo->rollBack();
			throw $e;
		}
	}

	/**
	 * Subscription-Statistiken aktualisieren.
	 * @param int $subscription_id
	 */
	static function updateStats(int $subscription_id): void {
		$pdo = Db::pdo();
		$sth = $pdo->prepare("UPDATE ttrss_plugin_newsletter_subscriptions
			SET message_count = message_count + 1, last_message_at = NOW()
			WHERE id = ?");
		$sth->execute([$subscription_id]);
	}

	/**
	 * Ordner/Kategorie einer Subscription ändern.
	 * @param int $subscription_id
	 * @param int|null $cat_id
	 * @param int $owner_uid
	 */
	static function updateCategory(int $subscription_id, ?int $cat_id, int $owner_uid): void {
		$pdo = Db::pdo();

		// Feed-ID holen
		$sth = $pdo->prepare("SELECT feed_id FROM ttrss_plugin_newsletter_subscriptions
			WHERE id = ? AND owner_uid = ?");
		$sth->execute([$subscription_id, $owner_uid]);
		$row = $sth->fetch();

		if ($row) {
			$sth = $pdo->prepare("UPDATE ttrss_feeds SET cat_id = ? WHERE id = ? AND owner_uid = ?");
			$sth->execute([$cat_id, $row['feed_id'], $owner_uid]);
		}
	}

	/**
	 * Auto-Labels einer Subscription aktualisieren.
	 * @param int $subscription_id
	 * @param string $labels Komma-getrennte Label-Namen
	 * @param int $owner_uid
	 */
	static function updateAutoLabels(int $subscription_id, string $labels, int $owner_uid): void {
		$pdo = Db::pdo();
		$sth = $pdo->prepare("UPDATE ttrss_plugin_newsletter_subscriptions
			SET auto_labels = ? WHERE id = ? AND owner_uid = ?");
		$sth->execute([$labels, $subscription_id, $owner_uid]);
	}

	/**
	 * Alle Subscriptions eines Benutzers laden.
	 * @param int $owner_uid
	 * @return array<array<string, mixed>>
	 */
	static function getAll(int $owner_uid): array {
		$pdo = Db::pdo();
		$sth = $pdo->prepare("SELECT ns.*, f.title AS feed_title, f.cat_id,
				fc.title AS category_title
			FROM ttrss_plugin_newsletter_subscriptions ns
			JOIN ttrss_feeds f ON f.id = ns.feed_id
			LEFT JOIN ttrss_feed_categories fc ON fc.id = f.cat_id
			WHERE ns.owner_uid = ?
			ORDER BY ns.last_message_at DESC NULLS LAST");
		$sth->execute([$owner_uid]);
		return $sth->fetchAll();
	}

	/**
	 * Subscription blockieren (Feed deaktivieren, Blocklist-Eintrag erstellen).
	 * @param int $subscription_id
	 * @param int $owner_uid
	 */
	static function blockSubscription(int $subscription_id, int $owner_uid): void {
		$pdo = Db::pdo();

		$sth = $pdo->prepare("SELECT sender_email, feed_id FROM ttrss_plugin_newsletter_subscriptions
			WHERE id = ? AND owner_uid = ?");
		$sth->execute([$subscription_id, $owner_uid]);
		$sub = $sth->fetch();

		if (!$sub) return;

		// Auf Blocklist setzen
		Newsletter_BlocklistManager::add_pattern($sub['sender_email'], true, $owner_uid);

		// Subscription deaktivieren
		$sth = $pdo->prepare("UPDATE ttrss_plugin_newsletter_subscriptions
			SET is_active = false WHERE id = ?");
		$sth->execute([$subscription_id]);

		// Feed verstecken
		$sth = $pdo->prepare("UPDATE ttrss_feeds SET hidden = true WHERE id = ? AND owner_uid = ?");
		$sth->execute([$sub['feed_id'], $owner_uid]);
	}

	/**
	 * Prüft ob eine feed_url ein Newsletter-Feed ist.
	 * @param string $feed_url
	 * @return bool
	 */
	static function isNewsletterFeed(string $feed_url): bool {
		return str_starts_with($feed_url, self::FEED_URL_PREFIX);
	}

	/**
	 * Subscription-Daten für eine Feed-ID laden.
	 * @param int $feed_id
	 * @param int $owner_uid
	 * @return array<string, mixed>|null
	 */
	static function getByFeedId(int $feed_id, int $owner_uid): ?array {
		$pdo = Db::pdo();
		$sth = $pdo->prepare("SELECT * FROM ttrss_plugin_newsletter_subscriptions
			WHERE feed_id = ? AND owner_uid = ?");
		$sth->execute([$feed_id, $owner_uid]);
		$row = $sth->fetch();
		return $row ?: null;
	}
}
