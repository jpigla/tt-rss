CREATE TABLE IF NOT EXISTS ttrss_plugin_track_changes (
	id SERIAL PRIMARY KEY,
	owner_uid INTEGER NOT NULL REFERENCES ttrss_users(id) ON DELETE CASCADE,
	url TEXT NOT NULL,
	title VARCHAR(500) NOT NULL DEFAULT '',
	css_selector VARCHAR(500) NOT NULL DEFAULT '',
	last_hash VARCHAR(64) NOT NULL DEFAULT '',
	last_content TEXT NOT NULL DEFAULT '',
	last_checked TIMESTAMP,
	check_interval INTEGER NOT NULL DEFAULT 3600,
	feed_id INTEGER,
	created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS ttrss_plugin_tc_owner_idx
	ON ttrss_plugin_track_changes(owner_uid);
