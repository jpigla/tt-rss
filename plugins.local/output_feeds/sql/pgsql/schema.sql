CREATE TABLE IF NOT EXISTS ttrss_plugin_output_feeds (
  id SERIAL PRIMARY KEY,
  owner_uid INTEGER NOT NULL REFERENCES ttrss_users(id) ON DELETE CASCADE,
  title VARCHAR(250) NOT NULL,
  feed_id INTEGER DEFAULT NULL,
  cat_id INTEGER DEFAULT NULL,
  label_id INTEGER DEFAULT NULL,
  tag VARCHAR(250) DEFAULT NULL,
  access_key VARCHAR(64) NOT NULL,
  max_items INTEGER NOT NULL DEFAULT 50,
  created_at TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS ttrss_plugin_of_key_idx ON ttrss_plugin_output_feeds(access_key);
