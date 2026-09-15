PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS la_ads (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  slug TEXT NOT NULL UNIQUE,
  ad_type TEXT NOT NULL CHECK(ad_type IN ('house','local','amazon','adsense')),
  label TEXT NOT NULL DEFAULT 'In evidenza',
  title TEXT NOT NULL,
  body TEXT NOT NULL DEFAULT '',
  target_url TEXT,
  area_id INTEGER,
  category_id INTEGER,
  priority INTEGER NOT NULL DEFAULT 100,
  frequency_cap INTEGER NOT NULL DEFAULT 3,
  active INTEGER NOT NULL DEFAULT 1,
  starts_at TEXT,
  ends_at TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(area_id) REFERENCES la_areas(id),
  FOREIGN KEY(category_id) REFERENCES la_categories(id)
);
CREATE INDEX IF NOT EXISTS idx_la_ads_active_priority ON la_ads(active,priority,id);

CREATE TABLE IF NOT EXISTS la_ad_events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  ad_id INTEGER NOT NULL,
  event_type TEXT NOT NULL CHECK(event_type IN ('impression','click','conversion')),
  area_id INTEGER,
  content_id INTEGER,
  value_cents INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(ad_id) REFERENCES la_ads(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_la_ad_events_date ON la_ad_events(created_at,event_type);

CREATE TABLE IF NOT EXISTS la_revenue_events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  source TEXT NOT NULL CHECK(source IN ('local','amazon','adsense','house')),
  amount_cents INTEGER NOT NULL DEFAULT 0,
  reference TEXT,
  area_id INTEGER,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_la_revenue_events_date ON la_revenue_events(created_at,source);

INSERT INTO la_settings(key,value) VALUES('product_name','LOCAL AUTOPILOT') ON CONFLICT(key) DO UPDATE SET value=excluded.value,updated_at=CURRENT_TIMESTAMP;
INSERT INTO la_settings(key,value) VALUES('public_area_label','Roma Ovest / Sud-Ovest') ON CONFLICT(key) DO UPDATE SET value=excluded.value,updated_at=CURRENT_TIMESTAMP;
INSERT INTO la_settings(key,value) VALUES('public_tagline','Notizie di quartiere') ON CONFLICT(key) DO UPDATE SET value=excluded.value,updated_at=CURRENT_TIMESTAMP;
INSERT INTO la_settings(key,value) VALUES('deploy_started_at',CURRENT_TIMESTAMP) ON CONFLICT(key) DO NOTHING;

INSERT OR IGNORE INTO la_areas(parent_id,name,slug,kind,cap_json,active) VALUES(NULL,'Roma','roma','city','[]',1);
INSERT OR IGNORE INTO la_areas(parent_id,name,slug,kind,cap_json,active) VALUES((SELECT id FROM la_areas WHERE slug='roma'),'Municipio XI','municipio-xi','municipio','[]',1);
INSERT OR IGNORE INTO la_areas(parent_id,name,slug,kind,cap_json,active) VALUES((SELECT id FROM la_areas WHERE slug='roma'),'Municipio XII','municipio-xii','municipio','[]',1);
INSERT OR IGNORE INTO la_areas(parent_id,name,slug,kind,cap_json,active) VALUES((SELECT id FROM la_areas WHERE slug='roma'),'Municipio XIII','municipio-xiii','municipio','[]',1);
INSERT OR IGNORE INTO la_areas(parent_id,name,slug,kind,cap_json,active) VALUES((SELECT id FROM la_areas WHERE slug='roma'),'Municipio I — Trastevere','municipio-i-trastevere','municipio','[]',1);

-- Municipio XII / asse Monteverde-Pisana-Massimina
INSERT OR IGNORE INTO la_areas(parent_id,name,slug,kind,cap_json,active) VALUES((SELECT id FROM la_areas WHERE slug='municipio-xii'),'Monteverde Vecchio','monteverde-vecchio','neighborhood','["00152"]',1);
INSERT OR IGNORE INTO la_areas(parent_id,name,slug,kind,cap_json,active) VALUES((SELECT id FROM la_areas WHERE slug='municipio-xii'),'Monteverde Nuovo','monteverde-nuovo','neighborhood','["00151"]',1);
INSERT OR IGNORE INTO la_areas(parent_id,name,slug,kind,cap_json,active) VALUES((SELECT id FROM la_areas WHERE slug='municipio-xii'),'Gianicolense','gianicolense','neighborhood','["00151","00152"]',1);
INSERT OR IGNORE INTO la_areas(parent_id,name,slug,kind,cap_json,active) VALUES((SELECT id FROM la_areas WHERE slug='municipio-xii'),'Colli Portuensi','colli-portuensi','neighborhood','["00151"]',1);
INSERT OR IGNORE INTO la_areas(parent_id,name,slug,kind,cap_json,active) VALUES((SELECT id FROM la_areas WHERE slug='municipio-xii'),'Casaletto','casaletto','neighborhood','["00151"]',1);
INSERT OR IGNORE INTO la_areas(parent_id,name,slug,kind,cap_json,active) VALUES((SELECT id FROM la_areas WHERE slug='municipio-xii'),'Bravetta','bravetta','neighborhood','["00164"]',1);
INSERT OR IGNORE INTO la_areas(parent_id,name,slug,kind,cap_json,active) VALUES((SELECT id FROM la_areas WHERE slug='municipio-xii'),'Villa Pamphilj','villa-pamphilj','neighborhood','["00152","00164"]',1);
INSERT OR IGNORE INTO la_areas(parent_id,name,slug,kind,cap_json,active) VALUES((SELECT id FROM la_areas WHERE slug='municipio-xii'),'Pisana','pisana','neighborhood','["00163","00164"]',1);
INSERT OR IGNORE INTO la_areas(parent_id,name,slug,kind,cap_json,active) VALUES((SELECT id FROM la_areas WHERE slug='municipio-xii'),'Massimina','massimina','neighborhood','["00166"]',1);
INSERT OR IGNORE INTO la_areas(parent_id,name,slug,kind,cap_json,active) VALUES((SELECT id FROM la_areas WHERE slug='municipio-xii'),'Casal Lumbroso','casal-lumbroso','neighborhood','["00166"]',1);
INSERT OR IGNORE INTO la_areas(parent_id,name,slug,kind,cap_json,active) VALUES((SELECT id FROM la_areas WHERE slug='municipio-xii'),'Pantan di Grano','pantan-di-grano','neighborhood','["00163","00166"]',1);
INSERT OR IGNORE INTO la_areas(parent_id,name,slug,kind,cap_json,active) VALUES((SELECT id FROM la_areas WHERE slug='municipio-xii'),'Ozanam','ozanam','neighborhood','["00152"]',1);
INSERT OR IGNORE INTO la_areas(parent_id,name,slug,kind,cap_json,active) VALUES((SELECT id FROM la_areas WHERE slug='municipio-xii'),'Jenner','jenner','neighborhood','["00151"]',1);
INSERT OR IGNORE INTO la_areas(parent_id,name,slug,kind,cap_json,active) VALUES((SELECT id FROM la_areas WHERE slug='municipio-xii'),'Ramazzini','ramazzini','neighborhood','["00151"]',1);
INSERT OR IGNORE INTO la_areas(parent_id,name,slug,kind,cap_json,active) VALUES((SELECT id FROM la_areas WHERE slug='municipio-xii'),'Aldobrandeschi / Pisana Esterna','aldobrandeschi-pisana-esterna','neighborhood','["00163"]',1);

-- Municipio XI / asse Portuense-Marconi fino alla Fiera
INSERT OR IGNORE INTO la_areas(parent_id,name,slug,kind,cap_json,active) VALUES((SELECT id FROM la_areas WHERE slug='municipio-xi'),'Portuense','portuense','neighborhood','["00149"]',1);
INSERT OR IGNORE INTO la_areas(parent_id,name,slug,kind,cap_json,active) VALUES((SELECT id FROM la_areas WHERE slug='municipio-xi'),'Marconi','marconi','neighborhood','["00146"]',1);
INSERT OR IGNORE INTO la_areas(parent_id,name,slug,kind,cap_json,active) VALUES((SELECT id FROM la_areas WHERE slug='municipio-xi'),'Trullo','trullo','neighborhood','["00148"]',1);
INSERT OR IGNORE INTO la_areas(parent_id,name,slug,kind,cap_json,active) VALUES((SELECT id FROM la_areas WHERE slug='municipio-xi'),'Corviale','corviale','neighborhood','["00148"]',1);
INSERT OR IGNORE INTO la_areas(parent_id,name,slug,kind,cap_json,active) VALUES((SELECT id FROM la_areas WHERE slug='municipio-xi'),'Casetta Mattei','casetta-mattei','neighborhood','["00148"]',1);
INSERT OR IGNORE INTO la_areas(parent_id,name,slug,kind,cap_json,active) VALUES((SELECT id FROM la_areas WHERE slug='municipio-xi'),'Ponte Galeria','ponte-galeria','neighborhood','["00148"]',1);
INSERT OR IGNORE INTO la_areas(parent_id,name,slug,kind,cap_json,active) VALUES((SELECT id FROM la_areas WHERE slug='municipio-xi'),'Piana del Sole','piana-del-sole','neighborhood','["00148"]',1);
INSERT OR IGNORE INTO la_areas(parent_id,name,slug,kind,cap_json,active) VALUES((SELECT id FROM la_areas WHERE slug='municipio-xi'),'Nuova Fiera di Roma','nuova-fiera-di-roma','neighborhood','["00148"]',1);
INSERT OR IGNORE INTO la_areas(parent_id,name,slug,kind,cap_json,active) VALUES((SELECT id FROM la_areas WHERE slug='municipio-xi'),'Villa Bonelli','villa-bonelli','neighborhood','["00149"]',1);
INSERT OR IGNORE INTO la_areas(parent_id,name,slug,kind,cap_json,active) VALUES((SELECT id FROM la_areas WHERE slug='municipio-xi'),'Vigna Pia','vigna-pia','neighborhood','["00149"]',1);
INSERT OR IGNORE INTO la_areas(parent_id,name,slug,kind,cap_json,active) VALUES((SELECT id FROM la_areas WHERE slug='municipio-xi'),'Santa Silvia','santa-silvia','neighborhood','["00148"]',1);
INSERT OR IGNORE INTO la_areas(parent_id,name,slug,kind,cap_json,active) VALUES((SELECT id FROM la_areas WHERE slug='municipio-xi'),'Pisana - Parco della Pace','pisana-parco-della-pace','neighborhood','["00148"]',1);

-- Municipio XIII / asse Aurelia-Boccea-Casalotti
INSERT OR IGNORE INTO la_areas(parent_id,name,slug,kind,cap_json,active) VALUES((SELECT id FROM la_areas WHERE slug='municipio-xiii'),'Aurelio','aurelio','neighborhood','["00165"]',1);
INSERT OR IGNORE INTO la_areas(parent_id,name,slug,kind,cap_json,active) VALUES((SELECT id FROM la_areas WHERE slug='municipio-xiii'),'Aurelia Antica','aurelia-antica','neighborhood','["00165"]',1);
INSERT OR IGNORE INTO la_areas(parent_id,name,slug,kind,cap_json,active) VALUES((SELECT id FROM la_areas WHERE slug='municipio-xiii'),'Gregorio VII','gregorio-vii','neighborhood','["00165"]',1);
INSERT OR IGNORE INTO la_areas(parent_id,name,slug,kind,cap_json,active) VALUES((SELECT id FROM la_areas WHERE slug='municipio-xiii'),'Villa Carpegna','villa-carpegna','neighborhood','["00165"]',1);
INSERT OR IGNORE INTO la_areas(parent_id,name,slug,kind,cap_json,active) VALUES((SELECT id FROM la_areas WHERE slug='municipio-xiii'),'Boccea','boccea','neighborhood','["00166","00167"]',1);
INSERT OR IGNORE INTO la_areas(parent_id,name,slug,kind,cap_json,active) VALUES((SELECT id FROM la_areas WHERE slug='municipio-xiii'),'Casalotti','casalotti','neighborhood','["00166"]',1);
INSERT OR IGNORE INTO la_areas(parent_id,name,slug,kind,cap_json,active) VALUES((SELECT id FROM la_areas WHERE slug='municipio-xiii'),'Val Cannuta','val-cannuta','neighborhood','["00166"]',1);
INSERT OR IGNORE INTO la_areas(parent_id,name,slug,kind,cap_json,active) VALUES((SELECT id FROM la_areas WHERE slug='municipio-xiii'),'Fogaccia','fogaccia','neighborhood','["00166"]',1);
INSERT OR IGNORE INTO la_areas(parent_id,name,slug,kind,cap_json,active) VALUES((SELECT id FROM la_areas WHERE slug='municipio-xiii'),'Valle Aurelia','valle-aurelia','neighborhood','["00167"]',1);
INSERT OR IGNORE INTO la_areas(parent_id,name,slug,kind,cap_json,active) VALUES((SELECT id FROM la_areas WHERE slug='municipio-xiii'),'Colle Aurelio','colle-aurelio','neighborhood','["00166"]',1);
INSERT OR IGNORE INTO la_areas(parent_id,name,slug,kind,cap_json,active) VALUES((SELECT id FROM la_areas WHERE slug='municipio-xiii'),'Montespaccato','montespaccato','neighborhood','["00166"]',1);
INSERT OR IGNORE INTO la_areas(parent_id,name,slug,kind,cap_json,active) VALUES((SELECT id FROM la_areas WHERE slug='municipio-xiii'),'Castel di Guido','castel-di-guido','neighborhood','["00166"]',1);

-- Municipio I, solo fascia editoriale coerente
INSERT OR IGNORE INTO la_areas(parent_id,name,slug,kind,cap_json,active) VALUES((SELECT id FROM la_areas WHERE slug='municipio-i-trastevere'),'Trastevere','trastevere','neighborhood','["00153"]',1);
INSERT OR IGNORE INTO la_areas(parent_id,name,slug,kind,cap_json,active) VALUES((SELECT id FROM la_areas WHERE slug='municipio-i-trastevere'),'Porta Portese','porta-portese','neighborhood','["00153"]',1);

INSERT OR IGNORE INTO la_categories(name,slug,kind,active) VALUES('Viabilità e trasporti','viabilita-trasporti','news',1);
INSERT OR IGNORE INTO la_categories(name,slug,kind,active) VALUES('Servizi e Municipio','servizi-municipio','news',1);
INSERT OR IGNORE INTO la_categories(name,slug,kind,active) VALUES('Ambiente e quartiere','ambiente-quartiere','news',1);
INSERT OR IGNORE INTO la_categories(name,slug,kind,active) VALUES('Scuola e famiglie','scuola-famiglie','news',1);
INSERT OR IGNORE INTO la_categories(name,slug,kind,active) VALUES('Avvisi utili','avvisi-utili','news',1);
INSERT OR IGNORE INTO la_categories(name,slug,kind,active) VALUES('Eventi','eventi','event',1);
INSERT OR IGNORE INTO la_categories(name,slug,kind,active) VALUES('Cultura e tempo libero','cultura-tempo-libero','event',1);
INSERT OR IGNORE INTO la_categories(name,slug,kind,active) VALUES('Famiglie','famiglie','event',1);
INSERT OR IGNORE INTO la_categories(name,slug,kind,active) VALUES('Attività locali','attivita-locali','business',1);
INSERT OR IGNORE INTO la_categories(name,slug,kind,active) VALUES('Servizi locali','servizi-locali','business',1);

INSERT OR IGNORE INTO la_ads(slug,ad_type,label,title,body,target_url,priority,frequency_cap,active)
VALUES('metodo-yoganostress','house','In evidenza','Metodo Yoganostress','Un percorso pratico per ridurre stress e ritrovare continuità, a Roma e online.','https://www.yoganostress.it/metodo-yoganostress/',20,2,1);
