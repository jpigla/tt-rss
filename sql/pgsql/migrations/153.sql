ALTER TABLE ttrss_feed_categories ADD COLUMN IF NOT EXISTS purge_interval integer NOT NULL DEFAULT 0;
ALTER TABLE ttrss_feed_categories ADD COLUMN IF NOT EXISTS max_articles integer NOT NULL DEFAULT 0;
