# YNS Direct Bridge

Canale primario a costo fisso €0 per manutenzione WordPress, indipendente dal limite WPVibe.

## Sicurezza
- Nessuna password WordPress nel repository.
- GitHub Actions usa un token OIDC a vita breve.
- Il plugin accetta solo il repository, owner ID, repository ID, branch e workflow autorizzati.
- Le azioni disponibili sono volutamente ristrette.
- Ogni modifica a post/pagine crea prima una revisione WordPress.

## Flusso
1. Modificare `ops/yns-direct/commands/live.json`.
2. Il push avvia `.github/workflows/yns-live-direct.yml`.
3. GitHub emette un OIDC token temporaneo.
4. WordPress verifica firma e claim.
5. Il comando viene eseguito direttamente sul sito.

WPVibe resta solo fallback/emergenza dopo il bootstrap iniziale del plugin.
