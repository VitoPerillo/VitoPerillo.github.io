# Professione Smart Mobile — Tap to Pay

Target iniziale: React Native + Stripe Terminal SDK.

## Flusso
1. Professionista autenticato in Professione Smart.
2. Backend risolve il connected account Stripe del professionista.
3. App richiede un ConnectionToken Terminal limitato alla Location corretta.
4. Professionista inserisce importo.
5. Backend calcola la fee Professione Smart in basis points.
6. Backend crea il PaymentIntent sul connected account con:
   - currency=eur
   - payment_method_types/card_present secondo il flusso Terminal
   - application_fee_amount calcolato server-side
7. App collega Tap to Pay reader.
8. App raccoglie e conferma il pagamento.
9. Backend verifica:
   - pagamento riuscito
   - connected account corretto
   - application fee creata
10. Audit append-only.

## Regole sicurezza
- fee mai calcolata dal client come fonte autorevole;
- secret Stripe solo server-side;
- connected account ricavato dalla sessione del professionista, non da input libero;
- importo validato server-side;
- nessun rimborso automatico;
- nessun accesso al Live durante sviluppo;
- Location e connected account devono essere nello stesso paese per Terminal.
