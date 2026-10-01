# Onboarding professionista — flusso definitivo

## 1. Registrazione Professione Smart
Il professionista inserisce:
- nome attività
- email
- telefono
- sede attività

Professione Smart crea un record interno con stato `registered`.

## 2. Collegamento provider
Provider disponibili:
- Stripe
- Mollie

Il provider non cambia l'esperienza utente principale.

## 3. Stripe Connect
Per Stripe:
- creare connected account moderno;
- merchant configuration;
- card payments richiesti;
- Stripe come fees collector;
- Stripe come losses collector;
- onboarding Stripe-hosted o embedded;
- salvare solo ID e stato, mai documenti d'identità nel database Professione Smart.

## 4. Gate di attivazione
Mostrare uno di questi stati:
- DA COMPLETARE
- IN VERIFICA
- ATTIVO
- BLOCCATO

POS abilitato soltanto quando card payments risultano attivi.

## 5. POS smartphone
Quando attivo:
- creare Location italiana del professionista;
- app mobile ottiene ConnectionToken autenticato;
- collegare Tap to Pay al telefono;
- creare PaymentIntent direct charge sul connected account;
- applicare automaticamente fee Professione Smart;
- processare il pagamento.

## 6. Commissione
Valore test: 50 bps = 0,50%.

Esempio:
100,00 € cliente
0,50 € Professione Smart
resto al professionista prima delle commissioni Stripe.

## 7. Sicurezza
Il client mobile NON può scegliere:
- connected account;
- percentuale della fee;
- Stripe secret;
- stato di onboarding.

Tutti questi valori vengono risolti lato server.
