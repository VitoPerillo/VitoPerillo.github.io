# LOCAL AUTOPILOT 0.4 — Test report

- Node test suite: **38/38 PASS**
- Syntax check: **PASS**
- Migration 0001 + 0002 su SQLite compatibile: **integrity_check=ok**
- Migration 0002 idempotente: **41 quartieri / 1 house ad** invariati al secondo passaggio
- Architettura: 1 D1 + 1 KV, nessun R2, nessun secondo D1 media
- UGC: email verification, magic link, rate limit, upload whitelist, metadata strip
- Monetizzazione: nessun pagamento automatico; AdSense/Amazon non attivi senza credenziali/policy gate
- Live Cloudflare: da eseguire sul PC autorizzato tramite launcher unico
