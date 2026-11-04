# VITO Universal Security Baseline Gate v2

Scopo: evitare PASS falsi nella verifica comune **EDGE → BOT/SPAM → RATE LIMIT → AUTH → API ABUSE**.

## Regole

- Conta solo evidenza in sorgenti/config runtime.
- Esclude documentazione, backup, release, artefatti, cache, build e dipendenze.
- L'evidenza presente soltanto nei test vale **PARTIAL**, non PASS.
- PASS richiede evidenza runtime forte.
- FAIL o PARTIAL bloccano il gate.
- Un controllo può essere N/A solo tramite `.vito-security.json` con una motivazione esplicita di almeno 12 caratteri.

Esempio:

```json
{
  "exceptions": {
    "bot_spam": "Nessun form pubblico o endpoint anonimo nel runtime"
  }
}
```

Uso:

```bash
node security/universal-baseline-gate.mjs <cartella-progetto>
node security/universal-baseline-gate.mjs --report-only <cartella-progetto>
node security/universal-baseline-gate.selftest.mjs
```

Questo gate non sostituisce test E2E o verifica provider WAF: impedisce che semplici parole in README, backup o artefatti vengano scambiate per protezioni reali.
