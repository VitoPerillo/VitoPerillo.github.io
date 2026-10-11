CREATE TABLE IF NOT EXISTS la_admin_login_codes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  code_salt TEXT NOT NULL,
  code_hash TEXT NOT NULL,
  attempts INTEGER NOT NULL DEFAULT 0,
  expires_at TEXT NOT NULL,
  consumed_at TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_la_admin_codes_active ON la_admin_login_codes(expires_at, consumed_at, id);

CREATE TABLE IF NOT EXISTS la_admin_sessions (
  session_hash TEXT PRIMARY KEY,
  expires_at TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_la_admin_sessions_expiry ON la_admin_sessions(expires_at);
