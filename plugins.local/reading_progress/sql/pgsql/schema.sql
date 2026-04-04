CREATE TABLE IF NOT EXISTS ttrss_plugin_reading_progress (
  id SERIAL PRIMARY KEY,
  ref_id INTEGER NOT NULL,
  owner_uid INTEGER NOT NULL REFERENCES ttrss_users(id) ON DELETE CASCADE,
  progress_pct FLOAT NOT NULL DEFAULT 0,
  last_position INTEGER NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE UNIQUE INDEX IF NOT EXISTS ttrss_plugin_rp_unique ON ttrss_plugin_reading_progress(ref_id, owner_uid);
