CREATE TABLE IF NOT EXISTS ttrss_plugin_podcast_progress (
  id SERIAL PRIMARY KEY,
  owner_uid INTEGER NOT NULL REFERENCES ttrss_users(id) ON DELETE CASCADE,
  enclosure_url TEXT NOT NULL,
  position_seconds FLOAT NOT NULL DEFAULT 0,
  completed BOOLEAN NOT NULL DEFAULT FALSE,
  last_played TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE UNIQUE INDEX IF NOT EXISTS ttrss_plugin_pp_unique ON ttrss_plugin_podcast_progress(owner_uid, enclosure_url);
