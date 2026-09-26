PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS profiles (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  public_id TEXT NOT NULL UNIQUE,
  email_hash TEXT NOT NULL UNIQUE,
  email_ciphertext TEXT,
  birth_ciphertext TEXT,
  plan TEXT NOT NULL DEFAULT 'fenice' CHECK(plan IN ('fenice','pegaso','orione','andromeda')),
  cadence TEXT CHECK(cadence IN ('monthly','annual') OR cadence IS NULL),
  trial_ends TEXT,
  subscription_id TEXT,
  subscription_status TEXT,
  paypal_plan_id TEXT,
  pending_plan TEXT,
  pending_cadence TEXT,
  pending_subscription_id TEXT,
  pending_paypal_plan_id TEXT,
  pending_state_hash TEXT,
  pending_started_at TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS sessions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  profile_id INTEGER NOT NULL,
  token_hash TEXT NOT NULL UNIQUE,
  expires_at TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(profile_id) REFERENCES profiles(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS usage_monthly (
  profile_id INTEGER NOT NULL,
  period_key TEXT NOT NULL,
  questions_used INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY(profile_id, period_key),
  FOREIGN KEY(profile_id) REFERENCES profiles(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS receipts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  profile_id INTEGER,
  transaction_id TEXT UNIQUE,
  subscription_id TEXT,
  amount TEXT,
  currency TEXT DEFAULT 'EUR',
  plan TEXT,
  cadence TEXT,
  payload_json TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(profile_id) REFERENCES profiles(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  profile_id INTEGER,
  event_type TEXT NOT NULL,
  payload_json TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(profile_id) REFERENCES profiles(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_events_profile_created ON events(profile_id, created_at);
CREATE INDEX IF NOT EXISTS idx_profiles_subscription ON profiles(subscription_id);
