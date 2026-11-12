# VITO Anti-Spam / Anti-Bot Baseline v1

Date: 2026-11-12

## Scope

Common security layer for public forms and anonymous abuse-prone endpoints.

Priority chain:

`EDGE → BOT/SPAM → RATE LIMIT → AUTH → API ABUSE`

## Evidence

The universal gate was executed in report-only mode on 8 current local project roots:

- LOCAL-AUTOPILOT-V1
- MR Control Hub
- Professione Smart
- Ritratto Stellare v2
- Spiritual Coach
- Yoganostress Commerciale AI
- Gestionale Yoganostress
- Chakra Wholeness

Result: **BOT/SPAM FAIL in 8/8** because no strong runtime evidence was present.

Other layers were not uniformly absent: AUTH and API ABUSE were already PASS in several runtimes, while EDGE and RATE LIMIT were mixed PASS/PARTIAL/FAIL. BOT/SPAM is therefore the first common gap to close.

## Common component created

Local canonical working copy:

`C:\Users\info\VITO-SECURITY-BASELINE\anti-spam-shield\`

Controls:

1. server-side honeypot;
2. server-minted HMAC-SHA256 challenge;
3. minimum/maximum challenge age;
4. mandatory rate-limit decision, fail-closed if unavailable;
5. optional or required Turnstile verifier;
6. no PII in rejection codes.

No new paid service or account is required.

## Verification

- JavaScript adapter: **PASS 8/8**
- Python adapter: **PASS 8/8** in isolated runtime
- PHP adapter: **php -l PASS + PASS 8/8** in isolated runtime
- Universal baseline gate selftest after BOT/SPAM detector update: **PASS**

## Important release rule

The existence of this common component does **not** promote any project to BOT/SPAM PASS.

A project becomes PASS only after runtime integration + negative E2E evidence. Projects without any public/anonymous attack surface must declare an explicit justified N/A in `.vito-security.json`.

## Next rollout criterion

Integrate the Shield only into actual public anonymous surfaces, starting from the highest-exposure project. Do not add it blindly to already-authenticated endpoints or signed webhooks.
