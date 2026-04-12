ALTER TABLE ttrss_plugin_newsletter_inboxes ADD COLUMN IF NOT EXISTS imap_pass_encrypted TEXT;
ALTER TABLE ttrss_plugin_newsletter_inboxes ADD COLUMN IF NOT EXISTS rate_limit_count INTEGER NOT NULL DEFAULT 0;
ALTER TABLE ttrss_plugin_newsletter_inboxes ADD COLUMN IF NOT EXISTS rate_limit_reset TIMESTAMP;
ALTER TABLE ttrss_plugin_newsletter_inboxes ADD COLUMN IF NOT EXISTS default_cat_id INTEGER REFERENCES ttrss_feed_categories(id) ON DELETE SET NULL;
