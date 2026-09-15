# RED TEAM PRE-CONGELAMENTO — LOCAL AUTOPILOT V1 ALPHA 0.3

## Verdetto architetturale

**CONGELABILE PER STAGING**, con nucleo: Workers + Static Assets + D1 + MediaStorageAdapter(KV) + Brevo Free + Gemini Free opzionale.

La precedente dipendenza da un secondo D1 per le immagini è stata eliminata. Anche R2 resta escluso perché, pur essendo tecnicamente ideale per object storage, non è coerente con il requisito più rigido di nessun overage automatico. Workers KV sul piano Free usa quote hard-limited e appartiene allo stesso account Cloudflare.

## Riutilizzato dalla V1 WordPress

- pipeline Source → Ingest → Normalize → Deduplicate → Geo → Risk → Value → AI → Fact → Publish → SEO → Social
- Risk/Value/Fact Gate e relativi casi di test
- source policy `auto/discovery/blocked`
- queue, retry, locking e health semantics
- UGC email verification + magic edit link
- moderation/report mechanism
- retention
- schema logico di news/event/business e territori/categorie
- SEO: canonical, metadata, schema, sitemap, news sitemap, hub territoriali e internal links
- social non bloccante
- requisito zero lavoro umano ordinario

## Riscritto perché infrastrutturale

- PHP/WordPress bootstrap → Worker ES modules
- CPT/taxonomie → tabelle D1 (`la_content`, `la_areas`, `la_categories`)
- MySQL/wpdb → D1 prepared statements
- WP-Cron → Cron Triggers
- Media Library → `MediaStorageAdapter`
- provider V1 media → Workers KV
- wp_mail → adapter Brevo
- wp-admin → dashboard/API protetta da bearer secret
- rewrite/template WordPress → routing Worker + Static Assets

## Eliminato

- plugin PHP WordPress come runtime di produzione
- `dbDelta`, capabilities, nonces WP, REST WP, hooks WP runtime
- secondo D1 media
- R2
- qualsiasi dipendenza da hosting Yoganostress

## Difetti oggettivi trovati e corretti in questo gate

1. **P0/P1 architettura media:** D1 usato come blob store avrebbe creato un limite stretto e un futuro cambio quasi certo. Corretto con `MediaStorageAdapter` + KV.
2. **P0 SSRF:** la validazione della URL sorgente avveniva solo all'inizio; un redirect poteva cambiare destinazione. Ora ogni redirect viene gestito manualmente e rivalidato.
3. **P1 copyright UGC:** checkbox diritti esisteva solo nel browser. Ora la dichiarazione viene inviata e richiesta anche dal server.
4. **P1 anti-spam:** il minimum form time previsto dalla specifica non era verificato server-side. Ora è validato.
5. **P1 integrità UGC:** area/categoria non venivano validate prima del write. Ora devono esistere ed essere attive; evento/attività deve usare la categoria coerente.
6. **P1 storage/privacy:** la rimozione richiesta dal proprietario non cancellava il media. Ora il media viene eliminato via adapter.
7. **P0 fail-closed media:** senza provider disponibile l'upload non viene perso o salvato altrove; ritorna errore recuperabile.

## Red team dei rischi residui

- Workers Free: CPU e request limits richiedono piccoli batch; già imposto MAX_QUEUE_BATCH e cron a job piccoli.
- KV Free: 1 GB storage, 1.000 write/day e 100.000 read/day sono adeguati alla V1 hyperlocal, ma sono quote hard. Media max 1 MB limita crescita e ogni failure resta esplicito.
- KV è eventually consistent: non è usato per locking, token, queue o stato critico. Solo media pubblico, che viene mostrato dopo verifica/moderazione; quindi l'eventuale breve propagazione non altera correttezza transazionale.
- Brevo/Gemini restano adapter esterni: indisponibilità non deve corrompere D1 né il sito.
- I test di concorrenza D1, cron reale, quote reali, email e Gemini richiedono staging Cloudflare.

## Regola di congelamento

Da questo punto non va più cambiata l'architettura per preferenze o ottimizzazioni marginali. Si cambia solo se uno specifico test P0 live dimostra un difetto reale.
