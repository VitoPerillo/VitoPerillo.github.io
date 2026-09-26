# RITRATTO STELLARE — MASTER DEFINITIVO

**Stato:** CONGELATO  
**Data:** 26 settembre 2026  
**Brand:** Ritratto Stellare · by Vega Gold  
**Regola commerciale:** FENICE → PEGASO → ORIONE → ANDROMEDA. STAR è solo la cadenza annuale dei tre piani a pagamento.  
**Esclusione:** il filone “€14,90 una tantum” NON sostituisce questo modello e non va riaperto nel prodotto principale.

## 1. Piani e prezzi congelati

| Piano | Mensile | Annuale STAR | Posizionamento |
|---|---:|---:|---|
| FENICE | €0 | — | Prova gratuita 30 giorni |
| PEGASO | €6,90 | €69 | Esperienza completa essenziale |
| ORIONE | €9,90 | €99 | Più scelto / interpretazione approfondita |
| ANDROMEDA | €14,90 | €149 | Massimo livello / visione integrata |

Nessuna carta richiesta per FENICE. PEGASO STAR, ORIONE STAR e ANDROMEDA STAR sono le versioni annuali; non sono piani aggiuntivi.

## 2. Funzioni già implementate e verificate in V1.5.2

- Registrazione/profilo con data, luogo e ora di nascita.
- Gestione dell’ora sconosciuta senza inventare Ascendente/case o altri dati dipendenti dall’ora.
- Ritratto Stellare personale.
- Letture Oggi, Settimana, Mese, Anno.
- Aree: Amore, Lavoro, Famiglia, Energia.
- Profondità crescente delle letture per piano.
- Domande personali: FENICE 1 durante la prova; PEGASO 1/mese; ORIONE 4/mese; ANDROMEDA 12/mese.
- PDF personale per i piani a pagamento.
- Login via codice email e riconoscimento dispositivo.
- PWA “IL MIO CIELO”.
- Web Push / preferenze notifiche.
- Regali / grant TEST senza pagamento e regali a pagamento.
- Supporto/FAQ separato dalle domande astrologiche.
- PayPal come unico sistema di pagamento.
- Catalogo PayPal Sandbox con 6 piani (3 mensili + 3 STAR), webhook configurato.
- Live commerciale disattivato fino ad autorizzazione esplicita.
- Ricevute di pagamento neutrali previste dal runtime.
- Dati utenti preservati negli upgrade 1.5.x.
- QA applicativo desktop/mobile superato; ultimo gate mobile: Performance 99, Accessibilità 100, Best Practices 100, CLS 0.
- Frontend pubblico separato su GitHub Pages: https://vitoperillo.github.io/ritratto-stellare/

## 3. Stato attuale non ancora chiuso

- Il frontend pubblico separato è LIVE ma i CTA di registrazione/acquisto puntano ancora allo staging Yoganostress.
- Backend/API autonomo esterno non ancora collegato.
- PayPal Sandbox E2E completo NON ancora certificato: manca prova conclusiva di approvazione buyer → return → webhook → attivazione → ricevuta.
- PayPal LIVE resta OFF.
- Privacy/Termini/IL MIO CIELO del frontend pubblico puntano ancora allo staging e dovranno essere portati sul dominio/infrastruttura separata.

## 4. Differenziazione definitiva dei piani

La differenza NON deve essere “più testo”. Ogni livello deve sbloccare utilità aggiuntive e riconoscibili.

### FENICE — Scoprire
- 30 giorni gratis.
- Ritratto essenziale.
- Oggi / Settimana / Mese / Anno.
- 4 aree: Amore, Lavoro, Famiglia, Energia.
- 1 domanda nell’intera prova.
- Nessun pagamento richiesto.

### PEGASO — Capire
- Tutto FENICE.
- Letture complete con più segnali e consigli pratici.
- PDF personale.
- 1 domanda al mese.
- Storico letture: 30 giorni.
- Notifiche essenziali scelte dall’utente.

### ORIONE — Orientarsi
- Tutto PEGASO.
- Letture approfondite.
- 4 domande al mese.
- **Bussola del Mese:** sintesi personale del tema dominante, aree da osservare e priorità riflessive del mese.
- **Momenti Chiave 30 giorni:** calendario delle date astrologicamente più sensibili con spiegazione semplice.
- Storico letture: 6 mesi.
- PDF Mese + Ritratto con sezioni estese.
- Notifiche personalizzate per Momenti Chiave, sempre disattivabili.

### ANDROMEDA — Collegare
- Tutto ORIONE.
- 12 domande al mese.
- **Radar 90 Giorni:** visione dei periodi più significativi dei prossimi 90 giorni.
- **Filo del Cielo:** collega i temi ricorrenti tra Amore, Lavoro, Famiglia ed Energia e tra Oggi/Settimana/Mese/Anno.
- **Mappa Annuale:** date e temi principali dell’anno in una vista consultabile.
- Storico letture: 12 mesi.
- PDF Ritratto + Mese + Anno.
- Notifiche evolute su finestre personali e cambi di fase, sempre opt-in.
- Nessuna funzione formula certezze sul futuro o sostituisce decisioni mediche, finanziarie, legali o psicologiche.

## 5. Gate funzionali per la differenziazione

Una funzione conta come implementata solo se:
1. è visibile nel piano corretto;
2. è bloccata/upsellata correttamente nei piani inferiori;
3. usa dati personali reali del profilo;
4. non è una semplice duplicazione di testo;
5. funziona su desktop e smartphone;
6. non altera quote, pagamenti o dati utenti esistenti;
7. mantiene il linguaggio astrologico come interpretazione riflessiva, non previsione certa.

## 6. PayPal — gate prima del LIVE

Ambiente obbligatorio: SANDBOX.

PASS solo quando un profilo sintetico completa:
1. scelta PEGASO Sandbox;
2. creazione subscription;
3. approvazione con buyer PayPal Sandbox Personal;
4. ritorno corretto a IL MIO CIELO;
5. webhook PayPal ricevuto e validato;
6. piano/cadenza attivati nel profilo sintetico;
7. ricevuta generata e inviata;
8. nessun dato reale modificato;
9. zero chiamate/addebiti LIVE.

Finché questo gate non è PASS, LIVE rimane OFF.

## 7. Infrastruttura separata a costo fisso €0

### Frontend
- GitHub Pages già attivo: `/ritratto-stellare/`.
- Repository GitHub = source of truth.

### Backend target
- Cloudflare Workers + D1 + KV/R2 solo entro free tier.
- Nessuna dipendenza runtime da WordPress/Yoganostress.
- Railway solo fallback/staging quando disponibile; non è il target primario a costo fisso €0.

### Migrazione
- API compatibili con registrazione, profilo, letture, domande, piano, PayPal webhook, ricevute, privacy/export/delete.
- D1 per dati strutturati.
- KV per cache/config non sensibile.
- Segreti PayPal solo come secret del runtime, mai nel repository.
- Cifratura dati di nascita lato backend.
- GitHub Actions/Cloudflare deploy con rollback.
- Healthcheck e fallback statico pubblico.
- Prima migrare profili sintetici; utenti reali solo dopo PASS completo.

## 8. Ordine di esecuzione congelato

1. Chiudere PayPal Sandbox E2E sul profilo sintetico.
2. Implementare e testare Bussola/Momenti Chiave ORIONE.
3. Implementare e testare Radar/Filo/Mappa Annuale ANDROMEDA.
4. Portare backend su Cloudflare separato.
5. Spostare Privacy/Termini/IL MIO CIELO fuori dallo staging Yoganostress.
6. Eseguire regressione completa.
7. Solo dopo, autorizzare PayPal LIVE con gate esplicito.

