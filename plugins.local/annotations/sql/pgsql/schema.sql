CREATE TABLE IF NOT EXISTS ttrss_plugin_annotations (
  id SERIAL PRIMARY KEY,
  ref_id INTEGER NOT NULL,
  owner_uid INTEGER NOT NULL REFERENCES ttrss_users(id) ON DELETE CASCADE,
  selector_path TEXT NOT NULL DEFAULT '',
  start_offset INTEGER NOT NULL DEFAULT 0,
  end_offset INTEGER NOT NULL DEFAULT 0,
  highlighted_text TEXT NOT NULL DEFAULT '',
  note TEXT NOT NULL DEFAULT '',
  color VARCHAR(20) NOT NULL DEFAULT '#fff3cd',
  markers TEXT NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS ttrss_plugin_ann_ref_idx ON ttrss_plugin_annotations(ref_id, owner_uid);
