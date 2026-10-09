# LOCAL AUTOPILOT 0.5 — Test report

Aggiornato: 2026-10-09

- Node test suite: **68/68 PASS**
- Syntax check, incluso il builder del feed: **PASS**
- Security Gate: **PASS**, zero blocchi; due avvisi non bloccanti già noti (`permissions_policy`, `audit`)
- Migrazioni 0001 + 0002 + bootstrap idempotente 0003: **PASS**
- Architettura: 1 Worker + 1 D1 + 1 KV; nessuna nuova risorsa e costo fisso €0
- UGC: moderazione umana obbligatoria, segnalazioni pubbliche, email verification, rate limit, whitelist upload e rimozione metadati
- Pubblicità: soltanto ordini di prova, pagamento simulato e €0 incassati
- Motore editoriale: pagina integrale, fatti verificati, testo originale 140–700 parole, immagine originale CC BY 4.0 e revisione amministrativa
- Bridge Roma Capitale: sola raccolta `discovery`; nessuna pubblicazione automatica degli estratti del feed
- Riparazione live prevista al primo ciclo dopo il deploy: gli articoli automatici incompleti vengono spostati in `held`
- Audit esteso a tutte le notizie pubblicate: gli estratti brevi, anche precedenti al bridge, vengono messi in revisione.
- Lotto editoriale del 9 ottobre: 3/3 articoli PASS su lunghezza, anti-copia, numeri, date, orari e nomi propri.
- Collaudo Cloudflare: home, 6 pagine articolo, 6 immagini e News Sitemap HTTP 200; 6/6 articoli conformi al gate.
- Secondo lotto: 3/3 articoli PASS (178–194 parole), pubblicato esclusivamente da contenuti trattenuti ancora attuali.
- Collaudo live esteso: 9/9 pagine, immagini e dati strutturati `NewsArticle` conformi; sitemap generale e News Sitemap HTTP 200.
- Indicizzazione: `robots.txt` live con entrambe le sitemap; News Sitemap con namespace e tag Google News obbligatori.
