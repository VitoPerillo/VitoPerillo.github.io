# VITO SECURITY BASELINE v1

Questa baseline definisce i controlli minimi comuni per i progetti dell'ecosistema.

## Regola di rilascio

- CRITICAL o HIGH: il rilascio deve essere bloccato.
- MEDIUM: warning da risolvere o accettare esplicitamente.
- LOW: hardening progressivo.
- N/A: controllo non applicabile e motivato.

## Controlli minimi

1. Autorizzazione server-side su ogni operazione privilegiata.
2. Rate limiting su login, form pubblici, API sensibili e webhook.
3. Protezione anti-spam sui form esposti.
4. Credenziali solo lato server o secret store.
5. Cookie Secure, HttpOnly e SameSite dove applicabile.
6. CSP, HSTS, X-Content-Type-Options, Referrer-Policy e Permissions-Policy.
7. CSRF sulle mutation browser basate su cookie o sessione.
8. Validazione e normalizzazione input server-side.
9. Upload con allowlist, limiti di dimensione e storage privato quando applicabile.
10. Webhook verificati server-side e protetti da replay quando applicabile.
11. Audit degli eventi privilegiati e degli errori di sicurezza.
12. Backup cifrato, checksum e prova di restore per dati persistenti.
13. Nessuna credenziale deve essere committata nel repository.

## Controlli AI

- separazione fra istruzioni di sistema e input non fidato;
- allowlist dei tool;
- autorizzazione per ogni azione sensibile;
- limiti per utente, IP e budget;
- documenti, email e contenuti esterni non sono mai istruzioni operative automatiche;
- sanitizzazione dell'output quando viene renderizzato.

## Principio operativo

Il Security Gate vive nel repository e nella pipeline. Le copie locali sono solo strumenti di lavoro e non sono necessarie per il deploy.
