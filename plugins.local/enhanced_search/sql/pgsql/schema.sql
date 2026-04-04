CREATE TABLE IF NOT EXISTS ttrss_plugin_saved_searches (
  id SERIAL PRIMARY KEY,
  owner_uid INTEGER NOT NULL REFERENCES ttrss_users(id) ON DELETE CASCADE,
  title VARCHAR(250) NOT NULL,
  query TEXT NOT NULL,
  feed_id INTEGER DEFAULT NULL,
  cat_id INTEGER DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS ttrss_plugin_ss_owner_idx ON ttrss_plugin_saved_searches(owner_uid);
