CREATE TABLE IF NOT EXISTS ttrss_plugin_newsletter_inboxes (
  id SERIAL PRIMARY KEY,
  owner_uid INTEGER NOT NULL REFERENCES ttrss_users(id) ON DELETE CASCADE,
  email_address VARCHAR(250) NOT NULL,
  imap_server VARCHAR(250) NOT NULL DEFAULT '',
  imap_port INTEGER NOT NULL DEFAULT 993,
  imap_user VARCHAR(250) NOT NULL DEFAULT '',
  imap_pass VARCHAR(250) NOT NULL DEFAULT '',
  imap_folder VARCHAR(100) NOT NULL DEFAULT 'INBOX',
  last_checked TIMESTAMP,
  created_at TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS ttrss_plugin_ni_owner_idx ON ttrss_plugin_newsletter_inboxes(owner_uid);
