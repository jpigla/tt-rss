CREATE TABLE IF NOT EXISTS ttrss_plugin_parser_rules (
  id SERIAL PRIMARY KEY,
  owner_uid INTEGER NOT NULL REFERENCES ttrss_users(id) ON DELETE CASCADE,
  domain VARCHAR(250) NOT NULL,
  rule_type VARCHAR(20) NOT NULL DEFAULT 'include'
    CHECK (rule_type IN ('include', 'exclude')),
  selector_css TEXT NOT NULL DEFAULT '',
  selector_xpath TEXT NOT NULL DEFAULT '',
  sample_text TEXT NOT NULL DEFAULT '',
  sample_url TEXT NOT NULL DEFAULT '',
  sample_html TEXT NOT NULL DEFAULT '',
  confidence FLOAT NOT NULL DEFAULT 0.8,
  hit_count INTEGER NOT NULL DEFAULT 0,
  miss_count INTEGER NOT NULL DEFAULT 0,
  last_hit_at TIMESTAMPTZ,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  llm_reasoning TEXT NOT NULL DEFAULT '',
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS ttrss_plugin_pr_domain_idx
  ON ttrss_plugin_parser_rules(owner_uid, domain, is_active);
