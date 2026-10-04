CREATE TABLE IF NOT EXISTS oauth_connections (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  organization_id TEXT UNIQUE,
  organization_name TEXT,
  access_token_enc TEXT NOT NULL,
  refresh_token_enc TEXT NOT NULL,
  iv_access TEXT NOT NULL,
  iv_refresh TEXT NOT NULL,
  scope TEXT,
  expires_at INTEGER,
  created_at INTEGER NOT NULL,
  updated_at INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS pos_payments (
  id TEXT PRIMARY KEY,
  organization_id TEXT NOT NULL,
  profile_id TEXT NOT NULL,
  terminal_id TEXT NOT NULL,
  amount_cents INTEGER NOT NULL,
  fee_cents INTEGER NOT NULL,
  status TEXT NOT NULL,
  created_at INTEGER NOT NULL,
  updated_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_pos_payments_org_created
ON pos_payments(organization_id, created_at DESC);

CREATE TABLE IF NOT EXISTS pos_payments (
  id TEXT PRIMARY KEY,
  organization_id TEXT NOT NULL,
  profile_id TEXT,
  terminal_id TEXT,
  amount_cents INTEGER NOT NULL,
  fee_cents INTEGER NOT NULL,
  status TEXT NOT NULL,
  created_at INTEGER NOT NULL,
  updated_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_pos_payments_org ON pos_payments(organization_id);