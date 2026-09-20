PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS la_moderation_actions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  entity_type TEXT NOT NULL CHECK(entity_type IN ('submission','report','ad_order','content')),
  entity_id INTEGER NOT NULL,
  action TEXT NOT NULL CHECK(action IN ('held','approved','rejected','removed','restored')),
  reason_code TEXT NOT NULL,
  notes TEXT,
  automated INTEGER NOT NULL DEFAULT 0 CHECK(automated IN (0,1)),
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_la_moderation_entity ON la_moderation_actions(entity_type,entity_id,created_at);

CREATE TABLE IF NOT EXISTS la_ad_orders (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  business_name TEXT NOT NULL,
  email TEXT NOT NULL,
  email_hash TEXT NOT NULL,
  area_id INTEGER NOT NULL,
  package_code TEXT NOT NULL CHECK(package_code IN ('local_week','local_month','featured_month')),
  title TEXT NOT NULL,
  body TEXT NOT NULL,
  target_url TEXT NOT NULL,
  price_cents INTEGER NOT NULL,
  manage_token_hash TEXT NOT NULL,
  risk_score INTEGER NOT NULL DEFAULT 0,
  status TEXT NOT NULL DEFAULT 'held' CHECK(status IN ('held','approved_test','mock_paid','rejected','cancelled')),
  rights_declared INTEGER NOT NULL CHECK(rights_declared=1),
  terms_accepted INTEGER NOT NULL CHECK(terms_accepted=1),
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_at TEXT,
  FOREIGN KEY(area_id) REFERENCES la_areas(id)
);
CREATE INDEX IF NOT EXISTS idx_la_ad_orders_status ON la_ad_orders(status,created_at);

INSERT INTO la_settings(key,value) VALUES('ads_payment_mode','test_no_payment')
ON CONFLICT(key) DO UPDATE SET value=excluded.value,updated_at=CURRENT_TIMESTAMP;
