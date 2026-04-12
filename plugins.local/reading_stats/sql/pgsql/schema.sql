CREATE TABLE IF NOT EXISTS ttrss_plugin_reading_stats (
	id SERIAL PRIMARY KEY,
	owner_uid INTEGER NOT NULL REFERENCES ttrss_users(id) ON DELETE CASCADE,
	ref_id INTEGER NOT NULL,
	feed_id INTEGER DEFAULT NULL,
	read_at TIMESTAMP NOT NULL DEFAULT NOW(),
	reading_time_sec INTEGER DEFAULT NULL,
	word_count INTEGER DEFAULT NULL
);

CREATE INDEX IF NOT EXISTS ttrss_plugin_rs_owner_idx
	ON ttrss_plugin_reading_stats(owner_uid, read_at DESC);
CREATE INDEX IF NOT EXISTS ttrss_plugin_rs_feed_idx
	ON ttrss_plugin_reading_stats(feed_id, read_at);

CREATE TABLE IF NOT EXISTS ttrss_plugin_reading_streaks (
	id SERIAL PRIMARY KEY,
	owner_uid INTEGER NOT NULL REFERENCES ttrss_users(id) ON DELETE CASCADE,
	current_streak INTEGER NOT NULL DEFAULT 0,
	longest_streak INTEGER NOT NULL DEFAULT 0,
	last_read_date DATE DEFAULT NULL,
	updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
	UNIQUE(owner_uid)
);
