CREATE TABLE IF NOT EXISTS ttrss_plugin_digest_configs (
	id SERIAL PRIMARY KEY,
	owner_uid INTEGER NOT NULL REFERENCES ttrss_users(id) ON DELETE CASCADE,
	title VARCHAR(200) NOT NULL DEFAULT 'Mein Digest',
	frequency VARCHAR(20) NOT NULL DEFAULT 'daily',
	schedule_day INTEGER DEFAULT 1,
	schedule_hour INTEGER NOT NULL DEFAULT 8,
	feed_ids TEXT NOT NULL DEFAULT '',
	cat_ids TEXT NOT NULL DEFAULT '',
	min_score INTEGER NOT NULL DEFAULT 0,
	max_articles INTEGER NOT NULL DEFAULT 50,
	send_email BOOLEAN NOT NULL DEFAULT false,
	enabled BOOLEAN NOT NULL DEFAULT true,
	last_generated TIMESTAMP DEFAULT NULL,
	created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS ttrss_plugin_digest_issues (
	id SERIAL PRIMARY KEY,
	config_id INTEGER NOT NULL REFERENCES ttrss_plugin_digest_configs(id) ON DELETE CASCADE,
	owner_uid INTEGER NOT NULL REFERENCES ttrss_users(id) ON DELETE CASCADE,
	title VARCHAR(250) NOT NULL,
	article_count INTEGER NOT NULL DEFAULT 0,
	html_content TEXT NOT NULL DEFAULT '',
	generated_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS ttrss_plugin_digest_configs_owner_idx
	ON ttrss_plugin_digest_configs(owner_uid, enabled);

CREATE INDEX IF NOT EXISTS ttrss_plugin_digest_issues_owner_idx
	ON ttrss_plugin_digest_issues(owner_uid, generated_at DESC);
