# Professione Smart — ramo Stripe

Stato: **TEST MODE / non produzione**

Obiettivo:
- mantenere Mollie attivo in parallelo;
- usare Stripe Connect per gli account dei professionisti;
- usare Stripe Terminal / Tap to Pay su iPhone e Android compatibili;
- il professionista resta il soggetto che incassa;
- Professione Smart monetizza ogni transazione con una application fee;
- rimborsi, contestazioni e saldo del professionista restano sul suo account connesso, compatibilmente con il modello Stripe scelto.

## Modello scelto per il primo E2E

**Direct charges su connected account + application_fee_amount.**

Esempio configurabile:
- vendita cliente finale: 100,00 €
- fee Professione Smart: 0,50% = 0,50 €
- il pagamento viene creato sul connected account;
- la fee di 0,50 € viene accreditata alla piattaforma.

La percentuale NON è ancora congelata: 0,50% è solo il valore test iniziale.

## Componenti

1. onboarding Stripe Connect ospitato/embedded;
2. connected account per ogni professionista;
3. pagamento online test;
4. application fee test;
5. Terminal/Tap to Pay test;
6. webhook di sola registrazione eventi;
7. dashboard stato provider;
8. audit tecnico.

## Sicurezza

- nessuna secret key nel browser o nel repository;
- test mode prima di live mode;
- nessun rimborso automatico;
- nessun cambio alle commissioni Mollie;
- nessuna migrazione forzata da Mollie a Stripe.
