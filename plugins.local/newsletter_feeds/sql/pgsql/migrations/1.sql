CREATE TABLE IF NOT EXISTS ttrss_plugin_newsletter_subscriptions (
  id SERIAL PRIMARY KEY,
  inbox_id INTEGER NOT NULL REFERENCES ttrss_plugin_newsletter_inboxes(id) ON DELETE CASCADE,
  owner_uid INTEGER NOT NULL REFERENCES ttrss_users(id) ON DELETE CASCADE,
  feed_id INTEGER NOT NULL REFERENCES ttrss_feeds(id) ON DELETE CASCADE,
  sender_email VARCHAR(250) NOT NULL,
  sender_name VARCHAR(250) NOT NULL DEFAULT '',
  sender_domain VARCHAR(250) NOT NULL DEFAULT '',
  list_unsubscribe TEXT,
  auto_labels TEXT NOT NULL DEFAULT '',
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  last_message_at TIMESTAMP,
  message_count INTEGER NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT NOW(),
  UNIQUE(owner_uid, sender_email)
);
CREATE INDEX IF NOT EXISTS ttrss_plugin_ns_owner_idx ON ttrss_plugin_newsletter_subscriptions(owner_uid);
CREATE INDEX IF NOT EXISTS ttrss_plugin_ns_feed_idx ON ttrss_plugin_newsletter_subscriptions(feed_id);
CREATE INDEX IF NOT EXISTS ttrss_plugin_ns_sender_idx ON ttrss_plugin_newsletter_subscriptions(sender_email);

CREATE TABLE IF NOT EXISTS ttrss_plugin_newsletter_blocklist (
  id SERIAL PRIMARY KEY,
  owner_uid INTEGER NOT NULL REFERENCES ttrss_users(id) ON DELETE CASCADE,
  pattern VARCHAR(250) NOT NULL,
  is_block BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS ttrss_plugin_nb_owner_idx ON ttrss_plugin_newsletter_blocklist(owner_uid);

CREATE TABLE IF NOT EXISTS ttrss_plugin_newsletter_processed (
  id SERIAL PRIMARY KEY,
  inbox_id INTEGER NOT NULL REFERENCES ttrss_plugin_newsletter_inboxes(id) ON DELETE CASCADE,
  message_id VARCHAR(500) NOT NULL,
  processed_at TIMESTAMP NOT NULL DEFAULT NOW(),
  UNIQUE(inbox_id, message_id)
);
CREATE INDEX IF NOT EXISTS ttrss_plugin_np_inbox_idx ON ttrss_plugin_newsletter_processed(inbox_id);
