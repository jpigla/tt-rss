CREATE TABLE IF NOT EXISTS ttrss_plugin_feed_health (
	id SERIAL PRIMARY KEY,
	feed_id INTEGER NOT NULL REFERENCES ttrss_feeds(id) ON DELETE CASCADE,
	owner_uid INTEGER NOT NULL REFERENCES ttrss_users(id) ON DELETE CASCADE,
	check_time TIMESTAMP NOT NULL DEFAULT NOW(),
	status VARCHAR(20) NOT NULL DEFAULT 'ok',
	error_message TEXT NOT NULL DEFAULT '',
	response_time_ms INTEGER DEFAULT NULL,
	articles_count INTEGER NOT NULL DEFAULT 0,
	last_article_date TIMESTAMP DEFAULT NULL
);

CREATE INDEX IF NOT EXISTS ttrss_plugin_fh_feed_idx
	ON ttrss_plugin_feed_health(feed_id, check_time DESC);
CREATE INDEX IF NOT EXISTS ttrss_plugin_fh_owner_idx
	ON ttrss_plugin_feed_health(owner_uid, check_time DESC);
