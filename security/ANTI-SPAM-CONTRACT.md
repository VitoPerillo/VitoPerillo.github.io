# VITO Anti-Spam / Anti-Bot Contract v1

Obiettivo: requisito comune per **ogni endpoint pubblico anonimo** (form, login, richiesta OTP/magic link, contatto, lead, iscrizione, endpoint AI pubblico, callback non firmata).

## Regola fail-closed

Un endpoint pubblico anonimo è PASS solo se applica **tutti** i controlli pertinenti:

1. **Honeypot invisibile** lato form, validato lato server.
2. **Turnstile o challenge equivalente** verificato **lato server**; il token client da solo non vale PASS.
3. **Rate limit** almeno per IP + identificatore applicativo quando disponibile (email/account/telefono/hash).
4. **Risposta uniforme** agli errori sensibili per non favorire enumeration.
5. **TTL breve / single-use** per OTP, magic link e challenge.
6. **Replay protection** per token/challenge/azioni sensibili.
7. **No secret client-side**.
8. **Audit degli abusi** senza salvare password, token o secret.
9. **Fail closed** se la verifica anti-bot o il rate-limit backend non è disponibile.
10. **Nessun CAPTCHA/Turnstile obbligatorio** su API server-to-server già protette da firma forte; lì valgono signature + timestamp + nonce + replay protection.

## Stati

- PASS: protezione runtime + test negativo reale.
- PARTIAL: codice presente ma test runtime/provider non verificato.
- FAIL: endpoint anonimo esposto senza protezione.
- N/A: nessun endpoint anonimo pertinente; motivazione obbligatoria in `.vito-security.json`.

## Ordine

EDGE/WAF → BOT/SPAM → RATE LIMIT → AUTH → API ABUSE.

BOT/SPAM non sostituisce RATE LIMIT e viceversa.
