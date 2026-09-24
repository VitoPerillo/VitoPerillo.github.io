# YOGANOSTRESS ADESSO — release pipeline

Percorso primario, senza TinyFish e senza WPVibe:

GitHub source package -> PHP/JS/JSON QA -> Playwright Dummy Test desktop + mobile -> ZIP deterministico -> SHA-256 -> release manifest -> YNS GitHub Deploy Agent -> Promotion Hub Plugin Bridge -> staging -> verifica reale -> rollback automatico se la verifica fallisce.

## Regole
- Il deploy non parte se il Dummy Test fallisce.
- Il manifest accetta solo il plugin `yoganostress-adesso`.
- Il pacchetto deve arrivare da questo repository e deve corrispondere al SHA-256.
- Il Plugin Bridge crea il backup prima della sostituzione atomica.
- Dopo il deploy l'Agent verifica versione, plugin attivo, endpoint plans e RESET TEST.
- Se il post-deploy gate fallisce, l'Agent richiama il rollback del Plugin Bridge.
- TinyFish e WPVibe restano soltanto bootstrap/fallback.

Il file sorgente atteso dalla CI è:
`.yns-deploy/yoganostress-adesso/source/current.zip.b64`

La CI decodifica il file, esegue i gate e solo su `main` pubblica un nuovo pacchetto in `releases/` e aggiorna `manifest.json`.
