# Professione Smart — architettura multi-provider

## Obiettivo
Un solo flusso utente, più provider di pagamento.

### Provider
- Mollie: mantenuto in parallelo, stato commerciale/approvazione separato.
- Stripe: ramo attivo in modalità test.

## Modello economico
Il professionista è il venditore e incassa sul proprio account presso il provider.
Professione Smart applica una commissione percentuale per transazione.

Valore test iniziale: **0,50% = 50 bps**.

Esempio:
- pagamento: 100,00 €
- commissione Professione Smart: 0,50 €
- quota lorda residua professionista prima delle commissioni del provider: 99,50 €

Le commissioni del provider restano separate dalla commissione Professione Smart.

## Regole
- nessun segreto nel repository;
- nessun pagamento live durante lo sviluppo;
- nessun rimborso automatico;
- nessuna modifica a Mollie per attivare Stripe;
- Tap to Pay viene abilitato solo sui provider/dispositivi compatibili;
- provider selezionato dietro un'unica interfaccia Professione Smart.

## Stripe
Per Stripe il target è:
1. connected account del professionista;
2. direct charge sul connected account;
3. application fee per Professione Smart;
4. Terminal/Tap to Pay per pagamento in presenza;
5. stato onboarding e capability verificati prima di accettare pagamenti.
