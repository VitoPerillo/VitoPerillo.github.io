# Primo test E2E Stripe — Professione Smart

## Gate 0 — account piattaforma
- Stripe account collegato a Professione Smart
- Connect attivo
- modalità TEST

## Gate 1 — professionista test
- creare/onboardare un connected account test
- verificare charges_enabled / payouts_enabled / requirements

## Gate 2 — pagamento test online
- creare un pagamento da 100,00 €
- applicare fee test Professione Smart dello 0,50% (0,50 €)
- verificare che la fee compaia come Application Fee sulla piattaforma
- verificare che il pagamento appartenga al connected account

## Gate 3 — rimborso
- NON eseguire automaticamente
- verificare soltanto ownership e responsabilità del refund nel modello scelto

## Gate 4 — Tap to Pay
- creare Location test
- inizializzare Terminal
- simulare o testare un pagamento contactless su dispositivo compatibile
- verificare che anche il pagamento in presenza conservi l'attribuzione al connected account e la fee di piattaforma

## Gate 5 — audit
Registrare:
- provider=stripe
- livemode=false
- connected_account_id
- payment_intent/charge id
- application_fee id
- amount
- platform_fee
- terminal/tap_to_pay flag
- esito
- timestamp

## Criterio PASS
PASS solo se:
1. il professionista riceve il pagamento sul proprio account connesso;
2. Professione Smart riceve la fee prevista;
3. Mollie resta invariato;
4. nessuna secret viene esposta;
5. nessun rimborso o payout viene eseguito dal test.
