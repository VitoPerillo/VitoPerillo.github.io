# Professione Smart — pipeline canonica

ChatGPT → **MR Bridge** → GitHub branch `professione-smart-staging` → Cloudflare Preview quando necessario → approvazione → `main` → Cloudflare LIVE → verifica LIVE.

MR Bridge è il control plane e il gate iniziale del progetto. Prima di qualsiasi Preview verifica sorgente/branch, sintassi minima, assenza di segreti committati, autorizzazione Cloudflare, visibilità dell'account/Worker e disponibilità delle API Workers Builds. Se uno di questi controlli fallisce, la pipeline si ferma in modalità fail-closed prima di modificare Cloudflare.

GitHub resta la sorgente canonica unica. La Preview Cloudflare non parte a ogni modifica: su `professione-smart-staging` viene avviata soltanto da un dispatch esplicito oppure da un commit intenzionale con marker `[preview]`.

TinyFish non fa parte del percorso normale. Desktop Commander / PC VitoPerillo sono esclusivamente break-glass/emergenza e non devono essere necessari per modifica, test, Preview, promozione o verifica LIVE.

Nessun segreto Mollie, Stripe o Cloudflare entra nel repository. Nessun pagamento reale viene eseguito dalla pipeline. La produzione non viene modificata senza approvazione esplicita.
