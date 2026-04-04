CREATE TABLE IF NOT EXISTS ttrss_plugin_metadata_overrides (
  id SERIAL PRIMARY KEY,
  ref_id INTEGER NOT NULL,
  owner_uid INTEGER NOT NULL REFERENCES ttrss_users(id) ON DELETE CASCADE,
  field_name VARCHAR(50) NOT NULL,
  override_value TEXT NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS ttrss_plugin_mo_ref_idx ON ttrss_plugin_metadata_overrides(ref_id, owner_uid);
