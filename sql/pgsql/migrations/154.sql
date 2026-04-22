-- NULL = inherit (category → global default)
-- 0   = explicitly use global default (skip category)
ALTER TABLE ttrss_feeds ALTER COLUMN purge_interval DROP NOT NULL;
ALTER TABLE ttrss_feeds ALTER COLUMN purge_interval SET DEFAULT NULL;
UPDATE ttrss_feeds SET purge_interval = NULL WHERE purge_interval = 0;

ALTER TABLE ttrss_feeds ALTER COLUMN max_articles DROP NOT NULL;
ALTER TABLE ttrss_feeds ALTER COLUMN max_articles SET DEFAULT NULL;
UPDATE ttrss_feeds SET max_articles = NULL WHERE max_articles = 0;
