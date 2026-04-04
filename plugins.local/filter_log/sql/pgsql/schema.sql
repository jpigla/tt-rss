CREATE TABLE IF NOT EXISTS ttrss_plugin_filter_log (
	id SERIAL PRIMARY KEY,
	owner_uid INTEGER NOT NULL REFERENCES ttrss_users(id) ON DELETE CASCADE,
	feed_id INTEGER,
	article_title TEXT NOT NULL DEFAULT '',
	article_link TEXT NOT NULL DEFAULT '',
	filter_id INTEGER,
	filter_title TEXT NOT NULL DEFAULT '',
	matched_rules TEXT NOT NULL DEFAULT '',
	actions TEXT NOT NULL DEFAULT '',
	triggered_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS ttrss_plugin_filter_log_owner_idx
	ON ttrss_plugin_filter_log(owner_uid);

CREATE INDEX IF NOT EXISTS ttrss_plugin_filter_log_triggered_idx
	ON ttrss_plugin_filter_log(triggered_at);
