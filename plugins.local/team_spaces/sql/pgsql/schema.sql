CREATE TABLE IF NOT EXISTS ttrss_plugin_teams (
  id SERIAL PRIMARY KEY,
  name VARCHAR(250) NOT NULL,
  owner_uid INTEGER NOT NULL REFERENCES ttrss_users(id) ON DELETE CASCADE,
  created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS ttrss_plugin_team_members (
  id SERIAL PRIMARY KEY,
  team_id INTEGER NOT NULL REFERENCES ttrss_plugin_teams(id) ON DELETE CASCADE,
  user_id INTEGER NOT NULL REFERENCES ttrss_users(id) ON DELETE CASCADE,
  role VARCHAR(20) NOT NULL DEFAULT 'member',
  joined_at TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE UNIQUE INDEX IF NOT EXISTS ttrss_plugin_tm_unique ON ttrss_plugin_team_members(team_id, user_id);

CREATE TABLE IF NOT EXISTS ttrss_plugin_team_shares (
  id SERIAL PRIMARY KEY,
  team_id INTEGER NOT NULL REFERENCES ttrss_plugin_teams(id) ON DELETE CASCADE,
  user_id INTEGER NOT NULL REFERENCES ttrss_users(id) ON DELETE CASCADE,
  ref_id INTEGER NOT NULL,
  comment TEXT NOT NULL DEFAULT '',
  shared_at TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS ttrss_plugin_ts_team_idx ON ttrss_plugin_team_shares(team_id);
