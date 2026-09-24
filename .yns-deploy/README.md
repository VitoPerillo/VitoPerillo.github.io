# YNS Direct Deploy control channel

Questa directory contiene solo il **manifest di controllo** del deploy dello staging Yoganostress.

- Nessuna credenziale è memorizzata qui.
- Nessuno ZIP del Gestionale viene pubblicato in chiaro.
- I payload di deploy sono cifrati prima del commit.
- L'Agent accetta solo il canale `staging-gestionale` e il plugin `yoganostress-prenotazioni`.
- Ogni pacchetto viene verificato con SHA-256, validazione ZIP/PHP, backup pre-deploy e rollback automatico.
- `manifest.json` resta con `"action": "none"` quando non esiste un deploy pendente.
