# Ritratto Stellare — audit completo da zero (03/10/2026)

## Decisione
Il precedente freeze A–I resta una prova storica valida dei test allora eseguiti, ma non è più sufficiente come gate di lancio. Il requisito ora verificato è più forte: ogni lettura Ritratto/Oggi/Settimana/Mese/Anno deve essere individuale e derivare da carta natale + transiti reali del periodo.

## Audit A → I riaperto

### A — Input personale
**PARZIALE.** Il frontend raccoglie data, ora opzionale e luogo, ma il flusso pubblico non risolve ancora il luogo in coordinate + timezone IANA storica prima del calcolo. Il motore richiede già timezone IANA, latitudine e longitudine.

### B — Effemeridi geocentriche
**PASS tecnico.** Il core usa GeoVector + Ecliptic geocentrico apparente per Sole, Luna e pianeti. La precedente correzione dell'uso eliocentrico resta corretta.

### C — Ora e fuso storico
**PASS del core / FAIL integrazione.** localCivilToUtc gestisce timezone IANA e i test includono Roma DST, New York, Sydney e Roma 1970. Il frontend però non fornisce ancora automaticamente la timezone IANA derivata dal luogo.

### D — Luogo, Ascendente, MC, case
**PASS del core / FAIL integrazione.** Angoli e Whole Sign sono calcolabili da coordinate reali, ma il percorso cliente non geocodifica ancora il luogo di nascita.

### E — Ora sconosciuta
**PASS del core.** Nessun Ascendente/MC/case inventato; la Luna viene valutata sull'intera giornata locale e l'incertezza viene segnalata. Va preservato in tutte le letture.

### F — Transiti
**PASS matematico.** transitsAt() calcola posizioni reali alla data richiesta.

### G — Oggi / Settimana / Mese / Anno
**PARZIALE.** analyzePeriod() crea finestre temporali locali e confronta transiti con pianeti natali. Tuttavia oggi restituisce dati/aspetti, non un oroscopo finale personalizzato consegnabile. Manca il livello interpretativo periodico collegato a profilo, aree e piano.

### H — Piani
**FAIL di coerenza repository.** Il frontend e lo standard aggiornato promettono PEGASO 4 aree/20 domande, FENICE 8/60, ANDROMEDA 12/150; file legacy plan-matrix/cloudflare/master contengono ancora il vecchio modello FENICE/ORIONE e quote 1/4/12. Devono essere neutralizzati o aggiornati prima del deploy per evitare regressioni.

### I — Verifica indipendente
**PASS per effemeridi/Asc/MC nei casi testati.** Swiss Ephemeris è registrata come riferimento indipendente con tolleranze. Questo non certifica da solo la qualità/personalizzazione dell'oroscopo testuale.

## Errori/blocchi reali scoperti
1. Il motore astronomico è più avanti del prodotto: produce carta/transiti, non ancora Oggi/Settimana/Mese/Anno testuali individuali completi.
2. Il luogo di nascita è attualmente testo libero; manca nel percorso pubblico la conversione verificata luogo → lat/lon/timezone IANA.
3. La personalizzazione periodica deve usare **natalChart + transiti del periodo + aspetti natal↔transito**; non basta cambiare il testo in base al segno.
4. Per Settimana/Mese/Anno serve selezionare e sintetizzare eventi/aspetti significativi nell'intera finestra, non usare un solo istante.
5. Ora sconosciuta deve propagarsi fino al testo periodico, impedendo riferimenti certi a case/Asc/MC.
6. Il repository contiene modelli commerciali legacy incompatibili con la promessa pubblica attuale.
7. Il nuovo rs-portrait-engine copre il Ritratto statico ma non costituisce ancora il generatore degli oroscopi temporali.
8. Mancano test di non-identità: due persone diverse, nello stesso giorno e stesso piano, devono produrre letture materialmente diverse per ragioni astrologiche tracciabili.
9. Mancano test longitudinali: stessa persona in giorni/mesi/anni diversi deve cambiare lettura quando cambiano transiti/aspetti.
10. Mancano test di coerenza semantica: il testo finale deve citare solo fattori realmente presenti nei dati calcolati.

## Nuovo gate obbligatorio prima del lancio
- P1: luogo → coordinate/timezone verificati.
- P2: carta natale individuale completa / unknown-time sicuro.
- P3: transiti reali per Oggi/Settimana/Mese/Anno.
- P4: aspetti natal↔transito reali.
- P5: generazione interpretativa periodica tracciabile ai fattori calcolati.
- P6: 3+ persone diverse nello stesso periodo => risultati materialmente diversi.
- P7: stessa persona in periodi diversi => risultati coerentemente diversi.
- P8: Pegaso/Fenice/Andromeda => stessa precisione astronomica, diversa ampiezza/profondità/quote.
- P9: nessun fallback a oroscopo generico per segno.
- P10: regressione independent-reference + unknown-time + timezone/DST.
- P11: pulizia/neutralizzazione configurazioni commerciali legacy.
- P12: E2E dal form cliente alla lettura finale.

Il Gate 1 qualità potrà tornare PASS solo dopo P1–P12 realmente eseguiti.
