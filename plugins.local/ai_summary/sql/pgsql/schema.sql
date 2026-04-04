CREATE TABLE IF NOT EXISTS ttrss_plugin_ai_summaries (
	id SERIAL PRIMARY KEY,
	ref_id INTEGER NOT NULL,
	owner_uid INTEGER NOT NULL REFERENCES ttrss_users(id) ON DELETE CASCADE,
	summary_type VARCHAR(20) NOT NULL DEFAULT 'short',
	summary_text TEXT NOT NULL DEFAULT '',
	model_used VARCHAR(100) NOT NULL DEFAULT '',
	created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS ttrss_plugin_ai_summaries_ref_idx
	ON ttrss_plugin_ai_summaries(ref_id, owner_uid, summary_type);
