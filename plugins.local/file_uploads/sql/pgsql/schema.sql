CREATE TABLE IF NOT EXISTS ttrss_plugin_uploads (
  id SERIAL PRIMARY KEY,
  owner_uid INTEGER NOT NULL REFERENCES ttrss_users(id) ON DELETE CASCADE,
  filename VARCHAR(500) NOT NULL,
  content_type VARCHAR(100) NOT NULL DEFAULT '',
  extracted_text TEXT NOT NULL DEFAULT '',
  file_size INTEGER NOT NULL DEFAULT 0,
  ref_id INTEGER,
  uploaded_at TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS ttrss_plugin_up_owner_idx ON ttrss_plugin_uploads(owner_uid);
