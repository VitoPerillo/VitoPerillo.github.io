# LOCAL AUTOPILOT — HANDOFF CANONICO

Aggiornato: 2026-09-19

## Fonte unica di verità

La fonte ufficiale è esclusivamente il branch `main` del repository pubblico:

`VitoPerillo/VitoPerillo.github.io`

Una funzione o un avanzamento è reale soltanto se presente nel repository e, quando riguarda Cloudflare, verificato anche sul servizio live.

## Stato live verificato

- Worker: `local-autopilot-v1`
- URL pubblico: https://local-autopilot-v1.black-sea-41df.workers.dev/
- Gestionale: https://local-autopilot-v1.black-sea-41df.workers.dev/admin
- Account Cloudflare: `b72d4037bafea30b480d3545812ab253`
- D1 riutilizzato: `local-autopilot`, ID `1c083f00-443c-4f86-9dae-fca94c9b616b`
- KV riutilizzato: `local-autopilot-media`, ID `7189620d965049f9ba52ab201268a3bc`
- Binding live: `DB`, `MEDIA_KV`, `ASSETS`
- Migrazioni D1 `0001` e `0002`: applicate
- Risorse duplicate create durante il collaudo: nessuna
- Architettura: Worker ES modules + Static Assets + un D1 + un KV
- Costo fisso richiesto: €0; nessun pagamento o upgrade automatico

## Collaudo live del 2026-09-19

Verifiche HTTP ripetute sul Worker live:

- `/`, `/quartieri`, `/eventi`, `/attivita`, `/segnala`, `/admin`: HTTP 200
- `/api/areas`, `/api/categories`, `/sitemap.xml`, `/news-sitemap.xml`: HTTP 200
- `/api/admin/health` anonimo: HTTP 403
- `/api/admin/health` autenticato: HTTP 200
- health finale: `OK`, `action_required=0`, `failed=0`, `sources_stale=0`, cron attivo
- 41 quartieri attivi
- 10 notizie campione pubblicate da fonti ufficiali
- news sitemap: 10 URL
- pagine delle 10 notizie: 10/10 HTTP 200
- gestionale: sezione “Notizie da revisionare” live

## Fonti ufficiali live

Quattro fonti Roma Capitale, tutte attive e in modalità `discovery`:

1. Municipio I / Trastevere
2. Municipio XI
3. Municipio XII
4. Municipio XIII

Le fonti `discovery` non autopubblicano. Gli elementi vengono raccolti e mostrati nel gestionale; la pubblicazione richiede l’azione amministrativa esplicita “Pubblica”. I contenuti a rischio RED restano bloccati.

## Correzioni dimostrate e pubblicate

- normalizzazione delle date italiane, inclusi valori come `18 settembre 2026`;
- fallback sicuro per date non riconosciute;
- endpoint protetto per eseguire una fonte;
- elenco protetto degli elementi da revisionare;
- pubblicazione/rifiuto amministrativo esplicito;
- pubblicazione deterministica a costo €0 per testi ufficiali approvati, senza dipendenza obbligatoria da Gemini;
- decodifica di virgolette, apostrofi e accenti HTML;
- idempotenza sugli elementi già completati;
- deduplicazione preventiva per URL/hash;
- le fonti `discovery` non occupano più la coda di elaborazione;
- bonifica live eseguita: 130 elementi spostati nella revisione e 140 job tecnici chiusi senza pubblicazione automatica;
- `keep_vars: true` conserva le variabili runtime durante i deploy.

## Test canonici

Comandi:

```bash
npm test
npm run check
```

Esito finale:

- `npm test`: 49/49 PASS
- `npm run check`: PASS
- commit codice live di preparazione coda: `2fde72818602032115457c416032b76fbc79cca6`
- commit test canonico: `5d827a41bdd83a5cc019748ff59872c6fbd60197`

## Segreti

`ADMIN_TOKEN`, `TOKEN_SECRET`, `GEMINI_API_KEY` e `BREVO_API_KEY` non devono essere committati. Il repository non contiene i loro valori.

Da completare come attività P1: ruotare `ADMIN_TOKEN` e `TOKEN_SECRET` e salvarli come segreti Cloudflare, perché sono stati mostrati durante la configurazione. Non riportarli in report, messaggi o commit.

## Stato dei gate

### P0

Nessun blocco P0 residuo rilevato nel nucleo staging: Worker, D1, KV, migrazioni, routing pubblico, cron, autenticazione admin, raccolta, revisione, pubblicazione, sitemap e rendering delle notizie sono verificati live.

### P1 residui

- rotazione dei due token esposti durante la configurazione e conversione in segreti;
- collaudo completo UGC con email reale, modifica/rimozione e media;
- collegamento e collaudo Brevo;
- Gemini opzionale: non necessario per il flusso editoriale manuale a costo €0;
- pubblicazione social automatica non ancora verificata;
- verifica visuale desktop/mobile conclusiva;
- Search Console non ancora collegata.

## Prossimo gate

Conservare le fonti in `discovery`, revisionare il backlog dal gestionale e pubblicare soltanto notizie attuali e realmente locali. Dopo una base iniziale coerente, collegare la sitemap a Google Search Console.

## Regola di modifica

Non creare altri Worker, D1 o KV. Mantenere `main` come unica fonte ufficiale. Non dichiarare completata una funzione esterna senza prova live ripetibile.
