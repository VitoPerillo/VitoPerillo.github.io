# Decisione POS smartphone — Stripe

## Requisito non negoziabile
Professione Smart deve guadagnare una percentuale su **ogni transazione** del professionista, non una semplice fee di attivazione.

## Esito verifica Stripe
Stripe offre Tap to Pay su iPhone e Android in Italia.

Esiste anche un percorso no-code tramite Stripe Dashboard mobile app, ma per il modello Professione Smart non è sufficiente perché la nostra fee per transazione deve essere inserita nel PaymentIntent creato dalla piattaforma tramite `application_fee_amount`.

## Architettura scelta
- Connected professional = merchant of record
- Direct charges
- Stripe collects Stripe processing fees
- Stripe manages connected-account losses/risk where supported
- Professione Smart creates the PaymentIntent
- Professione Smart sets `application_fee_amount`
- Tap to Pay runs through Stripe Terminal SDK in the Professione Smart mobile app
- Mollie remains parallel and untouched

## Perché non usiamo il percorso no-code Stripe Dashboard
Il merchant potrebbe accettare Tap to Pay subito, ma quel pagamento non attraverserebbe la nostra logica applicativa che calcola e imposta la fee dello 0,50%. Per il modello economico del progetto serve il percorso integrato.

## Lancio
La web app può andare online prima per:
1. presentazione;
2. registrazione professionista;
3. onboarding Stripe Connect;
4. stato attivazione.

La funzione POS sul telefono entra nel gate di go-live quando l'app mobile Tap to Pay passa l'E2E.
