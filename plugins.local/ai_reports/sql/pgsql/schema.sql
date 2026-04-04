CREATE TABLE IF NOT EXISTS ttrss_plugin_ai_reports (
  id SERIAL PRIMARY KEY,
  owner_uid INTEGER NOT NULL REFERENCES ttrss_users(id) ON DELETE CASCADE,
  title VARCHAR(500) NOT NULL,
  report_text TEXT NOT NULL DEFAULT '',
  source_articles TEXT NOT NULL DEFAULT '',
  model_used VARCHAR(100) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS ttrss_plugin_air_owner_idx ON ttrss_plugin_ai_reports(owner_uid);
