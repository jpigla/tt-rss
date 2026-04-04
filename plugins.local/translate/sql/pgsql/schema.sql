CREATE TABLE IF NOT EXISTS ttrss_plugin_translations (
  id SERIAL PRIMARY KEY,
  ref_id INTEGER NOT NULL,
  target_lang VARCHAR(10) NOT NULL,
  translated_title TEXT NOT NULL DEFAULT '',
  translated_content TEXT NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS ttrss_plugin_tr_ref_idx ON ttrss_plugin_translations(ref_id, target_lang);
