ALTER TABLE ttrss_feeds ADD COLUMN IF NOT EXISTS max_articles integer NOT NULL DEFAULT 0;
