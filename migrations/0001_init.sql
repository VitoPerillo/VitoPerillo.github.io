PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS la_sources (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  url TEXT NOT NULL,
  source_type TEXT NOT NULL,
  parser_type TEXT NOT NULL CHECK(parser_type IN ('rss','json','html')),
  area_id INTEGER,
  category_id INTEGER,
  trust_level INTEGER NOT NULL DEFAULT 50 CHECK(trust_level BETWEEN 0 AND 100),
  usage_policy TEXT NOT NULL CHECK(usage_policy IN ('auto','discovery','blocked')),
  interval_minutes INTEGER NOT NULL DEFAULT 60,
  last_checked_at TEXT,
  last_success_at TEXT,
  active INTEGER NOT NULL DEFAULT 1,
  config_json TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_la_sources_active ON la_sources(active);
CREATE INDEX IF NOT EXISTS idx_la_sources_last_checked ON la_sources(last_checked_at);
CREATE INDEX IF NOT EXISTS idx_la_sources_parser ON la_sources(parser_type);

CREATE TABLE IF NOT EXISTS la_ingest (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  source_id INTEGER NOT NULL,
  external_id TEXT,
  source_url TEXT NOT NULL,
  original_title TEXT NOT NULL,
  original_text TEXT NOT NULL,
  original_date TEXT,
  content_hash TEXT NOT NULL,
  fingerprint TEXT,
  status TEXT NOT NULL DEFAULT 'new',
  detected_area INTEGER,
  detected_category INTEGER,
  raw_payload TEXT,
  content_id INTEGER,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(source_id) REFERENCES la_sources(id) ON DELETE CASCADE
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_la_ingest_external ON la_ingest(source_id, external_id) WHERE external_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_la_ingest_hash ON la_ingest(content_hash);
CREATE INDEX IF NOT EXISTS idx_la_ingest_fingerprint ON la_ingest(fingerprint);
CREATE INDEX IF NOT EXISTS idx_la_ingest_status ON la_ingest(status);

CREATE TABLE IF NOT EXISTS la_jobs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  job_type TEXT NOT NULL,
  entity_id INTEGER,
  priority INTEGER NOT NULL DEFAULT 100,
  status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','processing','retry','completed','failed')),
  attempts INTEGER NOT NULL DEFAULT 0,
  available_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  locked_at TEXT,
  lock_token TEXT,
  last_error TEXT,
  payload_json TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_la_jobs_sched ON la_jobs(status, available_at, priority, id);
CREATE INDEX IF NOT EXISTS idx_la_jobs_lock ON la_jobs(locked_at);

CREATE TABLE IF NOT EXISTS la_submissions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  submission_type TEXT NOT NULL CHECK(submission_type IN ('event','business','report')),
  email TEXT NOT NULL,
  email_hash TEXT NOT NULL,
  verification_token_hash TEXT,
  edit_token_hash TEXT,
  payload_json TEXT NOT NULL,
  risk_score INTEGER,
  status TEXT NOT NULL DEFAULT 'pending_email',
  content_id INTEGER,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  verified_at TEXT,
  expires_at TEXT
);
CREATE INDEX IF NOT EXISTS idx_la_submissions_status ON la_submissions(status);
CREATE INDEX IF NOT EXISTS idx_la_submissions_email_hash ON la_submissions(email_hash);

CREATE TABLE IF NOT EXISTS la_reports (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  content_id INTEGER NOT NULL,
  reason TEXT NOT NULL,
  email_hash TEXT,
  details TEXT,
  risk_score INTEGER,
  status TEXT NOT NULL DEFAULT 'open' CHECK(status IN ('open','auto_resolved','held','resolved')),
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at TEXT
);
CREATE INDEX IF NOT EXISTS idx_la_reports_status ON la_reports(status);

CREATE TABLE IF NOT EXISTS la_logs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  level TEXT NOT NULL CHECK(level IN ('info','warning','error','critical')),
  component TEXT NOT NULL,
  message TEXT NOT NULL,
  context_json TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_la_logs_created ON la_logs(created_at);
CREATE INDEX IF NOT EXISTS idx_la_logs_level ON la_logs(level);

CREATE TABLE IF NOT EXISTS la_areas (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  parent_id INTEGER,
  name TEXT NOT NULL,
  slug TEXT NOT NULL UNIQUE,
  kind TEXT NOT NULL CHECK(kind IN ('city','municipio','neighborhood')),
  cap_json TEXT,
  active INTEGER NOT NULL DEFAULT 1
);
CREATE INDEX IF NOT EXISTS idx_la_areas_parent ON la_areas(parent_id);

CREATE TABLE IF NOT EXISTS la_categories (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  slug TEXT NOT NULL UNIQUE,
  kind TEXT NOT NULL CHECK(kind IN ('news','event','business')),
  active INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS la_content (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  content_type TEXT NOT NULL CHECK(content_type IN ('news','event','business')),
  slug TEXT NOT NULL UNIQUE,
  title TEXT NOT NULL,
  summary TEXT NOT NULL,
  body TEXT NOT NULL,
  area_id INTEGER,
  category_id INTEGER,
  source_id INTEGER,
  source_url TEXT,
  fingerprint TEXT,
  status TEXT NOT NULL DEFAULT 'published' CHECK(status IN ('draft','published','updated','resolved','expired','held')),
  published_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  valid_from TEXT,
  valid_until TEXT,
  confidence INTEGER,
  risk_level TEXT,
  original_hash TEXT,
  auto_generated INTEGER NOT NULL DEFAULT 0,
  event_start TEXT,
  event_end TEXT,
  venue TEXT,
  address TEXT,
  price_type TEXT,
  external_url TEXT,
  organizer TEXT,
  featured INTEGER NOT NULL DEFAULT 0,
  featured_until TEXT,
  phone TEXT,
  private_email TEXT,
  website TEXT,
  social TEXT,
  verified_email INTEGER NOT NULL DEFAULT 0,
  image_key TEXT,
  FOREIGN KEY(area_id) REFERENCES la_areas(id),
  FOREIGN KEY(category_id) REFERENCES la_categories(id)
);
CREATE INDEX IF NOT EXISTS idx_la_content_type_status ON la_content(content_type,status,published_at);
CREATE INDEX IF NOT EXISTS idx_la_content_area ON la_content(area_id,status,published_at);
CREATE INDEX IF NOT EXISTS idx_la_content_category ON la_content(category_id,status,published_at);
CREATE INDEX IF NOT EXISTS idx_la_content_fingerprint ON la_content(fingerprint);
CREATE INDEX IF NOT EXISTS idx_la_content_event_start ON la_content(event_start,status);

CREATE TABLE IF NOT EXISTS la_rate_limits (
  key_hash TEXT PRIMARY KEY,
  action TEXT NOT NULL,
  count INTEGER NOT NULL DEFAULT 0,
  window_start TEXT NOT NULL,
  expires_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS la_settings (
  key TEXT PRIMARY KEY,
  value TEXT,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
