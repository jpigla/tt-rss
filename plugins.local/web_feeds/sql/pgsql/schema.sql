CREATE TABLE IF NOT EXISTS ttrss_plugin_web_feeds_config (
  id SERIAL PRIMARY KEY,
  feed_id INTEGER NOT NULL,
  owner_uid INTEGER NOT NULL REFERENCES ttrss_users(id) ON DELETE CASCADE,
  list_selector VARCHAR(500) NOT NULL DEFAULT '',
  title_selector VARCHAR(500) NOT NULL DEFAULT '',
  link_selector VARCHAR(500) NOT NULL DEFAULT '',
  content_selector VARCHAR(500) NOT NULL DEFAULT '',
  date_selector VARCHAR(500) NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS ttrss_plugin_wf_feed_idx ON ttrss_plugin_web_feeds_config(feed_id, owner_uid);
