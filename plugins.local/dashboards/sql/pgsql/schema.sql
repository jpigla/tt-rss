CREATE TABLE IF NOT EXISTS ttrss_plugin_dashboards (
  id SERIAL PRIMARY KEY,
  owner_uid INTEGER NOT NULL REFERENCES ttrss_users(id) ON DELETE CASCADE,
  title VARCHAR(250) NOT NULL DEFAULT 'Dashboard',
  layout_json TEXT NOT NULL DEFAULT '[]',
  created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS ttrss_plugin_dashboard_widgets (
  id SERIAL PRIMARY KEY,
  dashboard_id INTEGER NOT NULL REFERENCES ttrss_plugin_dashboards(id) ON DELETE CASCADE,
  widget_type VARCHAR(50) NOT NULL,
  config_json TEXT NOT NULL DEFAULT '{}',
  position INTEGER NOT NULL DEFAULT 0
);
