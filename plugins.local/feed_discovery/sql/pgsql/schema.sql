CREATE TABLE IF NOT EXISTS ttrss_plugin_trending_topics (
	id SERIAL PRIMARY KEY,
	owner_uid INTEGER NOT NULL REFERENCES ttrss_users(id) ON DELETE CASCADE,
	topic VARCHAR(200) NOT NULL,
	article_count INTEGER NOT NULL DEFAULT 1,
	sample_ref_ids TEXT NOT NULL DEFAULT '',
	period_start DATE NOT NULL,
	period_end DATE NOT NULL,
	created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS ttrss_plugin_tt_owner_idx
	ON ttrss_plugin_trending_topics(owner_uid, period_end DESC);

CREATE TABLE IF NOT EXISTS ttrss_plugin_feed_suggestions (
	id SERIAL PRIMARY KEY,
	owner_uid INTEGER NOT NULL REFERENCES ttrss_users(id) ON DELETE CASCADE,
	feed_url TEXT NOT NULL,
	feed_title VARCHAR(250) NOT NULL DEFAULT '',
	site_url TEXT NOT NULL DEFAULT '',
	reason TEXT NOT NULL DEFAULT '',
	source VARCHAR(50) NOT NULL DEFAULT 'trending',
	score REAL NOT NULL DEFAULT 0,
	dismissed BOOLEAN NOT NULL DEFAULT false,
	subscribed BOOLEAN NOT NULL DEFAULT false,
	created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS ttrss_plugin_fs_owner_idx
	ON ttrss_plugin_feed_suggestions(owner_uid, dismissed, created_at DESC);
