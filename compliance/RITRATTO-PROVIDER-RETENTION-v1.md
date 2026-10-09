# Ritratto Stellare — Provider / Privacy / Retention baseline v1.0

Data: 9 ottobre 2026. PRE-LAUNCH.

## Provider
- PayPal: pagamento/abbonamento. PayPal tratta i dati necessari ai propri servizi di pagamento secondo la propria privacy; la versione italiana è aggiornata al 28/09/2026 e indica PayPal come titolare per i residenti SEE, oltre ai propri trasferimenti e garanzie.
- Cloudflare: infrastruttura target API/database/storage; DPA/SCC/subprocessor register da associare all'operatore finale.
- Email/push provider: NON congelato; qualsiasi scelta futura riapre provider/privacy gate.

Fonti ufficiali:
- https://www.paypal.com/it/legalhub/paypal/privacy-full
- https://www.cloudflare.com/trust-hub/gdpr/

## Dati
- email/account;
- data, ora e luogo di nascita;
- dati astrologici derivati;
- piano/cadenza/stato abbonamento e riferimenti PayPal;
- domande, storico e PDF quando abilitati;
- token sessione/push e log tecnici.

## Retention target
- account/profilo: fino a cancellazione o cessazione del servizio, salvo obblighi specifici;
- sessioni/token: fino a scadenza/revoca più breve margine tecnico;
- eventi tecnici: target massimo 90 giorni salvo incidente/security hold documentato;
- prova FENICE inattiva senza conversione: definire cleanup massimo 12 mesi dall'inattività prima del LIVE;
- ricevute/riferimenti di pagamento: per il periodo imposto all'operatore finale da obblighi fiscali/contabili;
- dati di nascita e storico: cancellabili con l'account salvo specifica base di conservazione.

## Sicurezza
- birth_ciphertext e email_ciphertext sono previsti nello schema target;
- segreti PayPal solo runtime secret;
- nessun PayPal LIVE finché LIVE_COMMERCIAL_AUTHORIZED non è true dopo gate;
- export/delete API devono essere testati prima di usare dati reali.

## Età
Baseline iniziale 18+, coerente anche con il requisito PayPal per i propri servizi.
