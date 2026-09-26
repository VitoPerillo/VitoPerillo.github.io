# Ritratto Stellare — Cloudflare backend

Target runtime separato da Yoganostress, costo fisso €0 entro i limiti free.

## Componenti
- Cloudflare Worker: API e webhook PayPal.
- D1: profili, sessioni, piani, domande, eventi, ricevute.
- KV: cache/config non sensibile.
- GitHub Pages: frontend pubblico già attivo.

## Regole
- Nessun segreto nel repository.
- `PAYPAL_CLIENT_ID`, `PAYPAL_CLIENT_SECRET`, `PAYPAL_WEBHOOK_ID`, chiave cifratura e mail provider vanno impostati come secrets del runtime.
- `PAYPAL_ENV=sandbox` finché il gate E2E non passa.
- Il Worker deve rifiutare LIVE se `LIVE_COMMERCIAL_AUTHORIZED !== "true"`.
- Prima migrazione solo profili sintetici.

## Gate
1. health PASS
2. schema D1 applicato
3. registrazione/OTP sintetico PASS
4. letture e quote PASS
5. PayPal Sandbox E2E PASS
6. ricevuta PASS
7. export/delete privacy PASS
8. regressione mobile/desktop PASS

Il master commerciale è in ../MASTER-RITRATTO-STELLARE.md.
