# LOCAL AUTOPILOT — HANDOFF CANONICO

Aggiornato: 2026-09-14

## Fonte unica di verità

La fonte ufficiale del progetto è il branch `main` del repository pubblico:

`VitoPerillo/VitoPerillo.github.io`

Una funzione, una configurazione o un avanzamento è considerato reale solo quando è presente in questo repository e, se riguarda un servizio esterno, è confermato anche da una verifica live ripetibile. Chat, mockup, launcher precedenti e rapporti non accompagnati dagli artefatti verificabili non costituiscono prova.

## Stato canonico

- Base sorgente: recuperata esclusivamente dal payload incorporato in `LOCAL-AUTOPILOT-DEPLOY-CORRETTO-V3.cmd`.
- Versione applicativa dichiarata dal sorgente: `0.4.0-live-beta`.
- Runtime previsto: Cloudflare Worker ES modules con Static Assets.
- Persistenza prevista: un database D1 per dati e stato critico; un namespace KV per i media pubblici.
- Dipendenze escluse dal runtime: WordPress/Yoganostress, R2 e un secondo D1 per i media.
- Test locali rieseguiti il 2026-09-14: **38/38 PASS**.
- Deploy Cloudflare live: **NON VERIFICATO in questa sessione**.
- URL pubblico applicativo: **NON DETERMINATO da evidenze presenti nel repository**.
- GitHub Pages: l'esecuzione riuscita del workflow predefinito non dimostra che il portale Worker sia pubblicato o funzionante.

## Requisiti funzionali presenti nel sorgente

- Pipeline editoriale Source → Ingest → Normalize → Deduplicate → Geo → Risk → Value → AI → Fact → Publish → SEO → Social.
- Copertura editoriale iniziale: Municipi XI, XII, XIII e Trastevere/Municipio I, con 41 quartieri e micro-zone.
- Gate rischio, valore locale, fatti e duplicati.
- Le fonti in modalità `discovery` non possono autopubblicare; le fonti bloccate non possono pubblicare.
- Contenuti UGC con verifica email, link personale di modifica/rimozione, rate limit, controllo diritti e whitelist upload.
- Dashboard amministrativa protetta da bearer token.
- Coda con claim atomico, retry, recupero lock scaduti e health semantico.
- Sitemap ordinaria e news sitemap limitata a due giorni.
- Adapter opzionali Gemini, Brevo e social; i loro errori non devono corrompere D1 né bloccare la pipeline principale.
- House Ads predisposte. Amazon e AdSense restano inattivi senza credenziali e relativi gate di conformità.
- Nessun pagamento, auto-upgrade o billing automatico.

## Evidenze locali ripetibili

Comandi:

```bash
npm test
npm run check
```

Esito verificato il 2026-09-14:

- `npm test`: 38 test, 38 superati, 0 falliti.
- `npm run check`: PASS su tutti i moduli JavaScript elencati nello script.
- Migrazioni `0001` + `0002` su SQLite compatibile: `integrity_check=ok`.
- Idempotenza `0002`: 41 quartieri, 46 aree totali e 1 House Ad invariati dopo la seconda applicazione.
- Test coperti: fail-closed P0/P1, sicurezza sorgenti e redirect SSRF, deduplica, coda, UGC, token, media KV, retention, sitemap, dashboard, architettura senza R2/secondo D1.
- Il precedente valore `115/115 PASS` non è assunto come canonico perché non è riproducibile dagli artefatti presenti.

## Configurazione e segreti

Il file `wrangler.jsonc` conserva intenzionalmente segnaposto per:

- `database_id` D1;
- `id` del namespace KV;
- `PUBLIC_BASE_URL`.

I segreti `TOKEN_SECRET`, `ADMIN_TOKEN`, `GEMINI_API_KEY` e `BREVO_API_KEY` non devono essere committati. Devono essere configurati nel servizio Cloudflare e verificati live. Finché identificativi, segreti e URL non sono ricavati dal servizio autorizzato, il repository documenta un progetto pronto al collegamento, non un deploy live dimostrato.

## Materiale escluso

- `CORRETTO-V2` e ogni launcher V2.
- `LOCAL-AUTOPILOT-DEPLOY-v2.cmd`.
- Duplicati del sorgente incorporati nei launcher.
- Mockup grafici usati come falsa prova di implementazione.
- Dichiarazioni `DEPLOYED`, test live o commit non riscontrabili nel repository o nel servizio esterno.
- Nomi o identificativi Cloudflare contraddittori provenienti dalle chat.

## Rischi e debito residuo

### P0

- Nessun deploy live verificato dal repository.
- Identificativi D1/KV e URL pubblico non ancora collegati nel file di configurazione.
- Migrazioni D1, cron, autorizzazione admin e routing Worker devono essere provati nell'ambiente Cloudflare reale.

### P1

- Brevo, Gemini e pubblicazione social richiedono test end-to-end con account reali.
- Quote Workers/KV Free richiedono osservazione in staging con piccoli batch.
- Il flusso UGC completo richiede prova browser desktop/mobile, email reale e rimozione media.

### P2

- Rifiniture visuali e contenuti iniziali vanno affrontati solo dopo il superamento dei gate P0/P1.

## Prossimo gate unico

1. Collegare questo commit al progetto Cloudflare corretto senza creare risorse duplicate.
2. Recuperare dal servizio gli identificativi effettivi di Worker, D1 e KV.
3. Aggiornare `wrangler.jsonc` mantenendo fuori dal repository tutti i segreti.
4. Applicare le migrazioni in staging.
5. Pubblicare e verificare almeno: homepage `200`, `/quartieri` `200`, `/api/areas` `200`, `/api/admin/health` anonimo `403`, `/api/admin/health` autenticato `200`, CRUD contenuti, cron, UGC e rendering desktop/mobile.
6. Dichiarare `STAGING VERIFICATO` soltanto con URL, commit e risultati ripetibili registrati in un nuovo report nel repository.

## Regola di modifica

L'architettura è congelata. Si modifica soltanto per correggere un difetto P0/P1 dimostrato da un test ripetibile. Ogni futuro handoff deve aggiornare questo file nello stesso commit del codice a cui si riferisce.


## Verifica Cloudflare — 2026-09-16

Base verificata: main a 2cfbf28bfa8af61285eab67464fec701d67f6c57.
Account: b72d4037bafea30b480d3545812ab253.
Il pannello Workers & Pages non contiene progetti. Sottodominio account verificato: black-sea-41df.workers.dev.
D1 riutilizzato: local-autopilot, ID 1c083f00-443c-4f86-9dae-fca94c9b616b.
Il KV preesistente dichiarato in chat non risulta: elenco API completo e pannello entrambi vuoti. Creato local-autopilot-media, ID 7189620d965049f9ba52ab201268a3bc.
Applicate le migrazioni 0001 e 0002 via API D1: 100 istruzioni riuscite, nessun errore. PRAGMA quick_check = ok. Aree: 41 quartieri, 4 municipi, 1 città. Etichetta pubblica e tagline verificate. Amazon/AdSense assenti e inattivi.
wrangler.jsonc aggiornato con ID reali e URL di destinazione derivato dal nome Worker e dal sottodominio effettivo. Nessun segreto incluso.
La configurazione è preparata, NON è prova di deploy. URL previsto: https://local-autopilot-v1.black-sea-41df.workers.dev; admin previsto: /admin.
Blocco alla pubblicazione: API Worker restituisce 10000 Authentication error. Il flusso GitHub nel pannello richiede la creazione di un nuovo token utente; nessun token esistente selezionabile. Creazione non ancora autorizzata al momento della verifica.
Secrets, deploy, cron live e test HTTP/admin/CRUD/UGC/desktop/mobile: NON ESEGUITI. Non dichiarare P0/P1 superati. Alla ripresa riusare D1/KV sopra e non ricreare risorse.


## Verifica live e correzione P0 — 2026-09-19

Stato verificato sul Worker `https://local-autopilot-v1.black-sea-41df.workers.dev` tramite API HTTP dirette, senza dashboard Cloudflare:

- homepage, quartieri, eventi, attività, form, admin, API aree/categorie e sitemap: HTTP 200;
- health anonimo: HTTP 403; health autenticato: HTTP 200;
- cron attivo, coda senza job falliti, D1 con 41 quartieri;
- quattro fonti ufficiali Roma Capitale configurate in modalità `discovery` per Municipio I/Trastevere, XI, XII e XIII; test parser live riuscito per tutte;
- nessuna pubblicazione automatica abilitata dalle fonti `discovery`.

Difetto P0 dimostrato live: l'ingestione falliva su date italiane come `18 settembre 2026` con `RangeError: Invalid time value`. Correzione pubblicata su `main`: normalizzazione sicura delle date italiane e fallback `null` per date non riconosciute. Aggiunto endpoint amministrativo autenticato `POST /api/admin/source/run` per collaudi mirati senza dashboard. Test locali: 42/42 PASS; controllo sintattico PASS.

Protezione deploy: `keep_vars: true` aggiunto a `wrangler.jsonc` per conservare le variabili runtime configurate nel dashboard durante i deploy, come previsto dalla documentazione Wrangler.

Blocco residuo reale: il collegamento Git visibile in Cloudflare non esegue automaticamente il deploy dei nuovi commit e in questa sessione non è disponibile alcun connettore/API Cloudflare con credenziali di deploy. Il Worker live resta quindi sulla versione precedente: il fix è canonico su GitHub ma non ancora live. Non dichiarare ingestione o staging completi finché `/api/admin/source/run` non restituisce HTTP 200 e le fonti mostrano `last_success_at` valorizzato. Non creare nuove risorse Worker, D1 o KV.
