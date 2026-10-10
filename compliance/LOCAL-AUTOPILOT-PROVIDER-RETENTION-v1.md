# Local Autopilot — Provider / Data / Retention Register v1.0

Data: 10 ottobre 2026. Profilo valutato: **editoriale**.

## Provider tecnici

| Provider | Funzione | Dati possibili | Regola |
|---|---|---|---|
| Cloudflare Workers / Assets | runtime e sito | richieste HTTP, log tecnici | infrastruttura primaria |
| Cloudflare D1 | database | contenuti, submission, email verificata, report, log | minimizzazione + retention applicativa |
| Cloudflare KV | media | immagini UGC ripulite dai metadati | solo se necessarie e con diritto d'uso |
| Google Gemini | supporto AI editoriale | testo della fonte/contenuto necessario al task | niente secret/contatti non necessari; provider change riapre gate |
| Brevo | email transazionale | email destinatario + contenuto verifica/gestione | solo verifica e notifiche operative; marketing separato |

L'identità contrattuale definitiva dei DPA/provider viene legata all'operatore finale prima del LIVE. Il provider register tecnico è congelato: un nuovo provider riapre il gate.

## Tracking/cookie
Nel codice revisionato non risultano Google Analytics, Meta Pixel, tracker pubblicitari o cookie non tecnici.
Il profilo editoriale non richiede CMP per tracker inesistenti. Se viene aggiunto tracking non tecnico, il cookie gate torna automaticamente BLOCKED.

## Retention applicativa
- ingest già pubblicato/aggiornato/rifiutato: 30 giorni;
- job completati: 14 giorni;
- log info: 14 giorni;
- log warning/error: 90 giorni;
- rate-limit record: fino a scadenza;
- token verifica submission: massimo 24 ore;
- submission approvate/rifiutate: dati identificativi/payload anonimizzati dopo 180 giorni;
- email privata nei contenuti: rimossa dopo 180 giorni;
- report risolti: email hash e dettagli anonimizzati dopo 180 giorni;
- ordini pubblicitari TEST chiusi: email/hash/token anonimizzati dopo 90 giorni;
- azioni moderazione: 365 giorni.

## Diritti
Il link personale permette modifica/rimozione della submission. La rimozione elimina l'immagine caricata quando presente e ritira il contenuto pubblico. Prima del profilo pubblico definitivo va pubblicato un contatto privacy dell'operatore finale per richieste non gestibili self-service.
