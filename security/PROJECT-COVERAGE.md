# Universal Security Coverage

## Policy

All current and future technical/digital projects are in scope by default.

**Security Gate PASS is not the same as Release PASS.**

A project is release-ready only when its applicable chain is evidenced:

`CANONICAL SOURCE → SECURITY GATE → DEPENDENCIES → STAGING → E2E → RED TEAM → BACKUP/RESTORE → ROLLBACK → RC → PRODUCTION → POST-DEPLOY`

Controls marked N/A must be justified.

See `security/SECURITY-RELEASE-BOARD.md` for the strict evidence matrix.

## Current coverage

| Project | Security status | Release status | Notes |
|---|---|---|---|
| Local Autopilot | PASS existing gate | PARTIAL | Canonical release/staging/E2E/restore/rollback evidence not fully reconciled in current board |
| Ritratto Stellare | PASS existing gate | PARTIAL | Existing deploy gate; full release chain not yet evidenced |
| MR Bridge / yoganostress-wordpress-bridge | PASS existing gate | PARTIAL | Staging/OIDC/backup/rollback capabilities exist; release-level restore/rollback exercise still partial |
| Professione Smart | PASS current-main local revalidation | PARTIAL | main b36a02da…: syntax + blocking gate + Wrangler dry-run PASS. Fresh GitHub Security Revalidation run 38528718009 blocked before steps by account billing/spending |
| Rivensight | PASS current-main local revalidation | PARTIAL | main 8ad2b80d…: syntax + tests + gate PASS; local /health 200. Fresh GitHub Security Revalidation run 38528736856 blocked before steps by account billing/spending |
| MR Control Hub | PASS | PARTIAL | Canonical private repo; staging health/security headers validated; remaining release-chain evidence incomplete |
| Chakra Wholeness | PASS remote build gate | PARTIAL | main 3b8feed…; Vercel preview dpl_2Y9nKSAgiPfAbZLBxpz7NJqGTmWk READY and remote Django security gate PASS; restore/rollback/full preview E2E incomplete |
| Gestionale Yoganostress | PASS canonical RC4 gate | PARTIAL | Byte-exact RC4 recovered and gated; final negative security + restore/rollback release exercise still incomplete |
| Spiritual Coach | PASS previous gate + Red Team | PARTIAL | 39/39 Red Team and staging health PASS; dependency/restore/rollback/full live E2E incomplete |
| Yoganostress Commerciale AI | PASS previous Cloudflare gate | PARTIAL | Consent/privacy tests + dry-run + health evidence; broader Red Team/restore/rollback incomplete |
| Vito AI | PASS universal gate | PARTIAL | Branch vito-ai-multicloud-rc14; pdfjs-dist upgraded to 6.3.289, npm 0 vulnerabilities; fresh security-gated provider staging deploy/post-deploy still missing |
| Vito Prompt / Prompt Coach | PASS shared Vito AI runtime gate | PARTIAL | Branch vito-ai-coach-staging; passwordless/Guided Discovery/ecosystem/Prompt Red Team PASS; provider staging/restore/rollback incomplete |
| Vito Document Engine | BLOCKED | BLOCKED | Canonical Phase 2B metadata known but exact bytes unavailable; do not downgrade to Phase 2A |
| Vito AutoLoop | N/A — spec only | N/A | No runtime yet; automatically enters gate when implementation starts |

## Current infrastructure blockers

- Fresh GitHub-hosted private-runner revalidation attempts for Professione Smart and Rivensight fail before any step because GitHub reports failed recent account payments or a required spending-limit increase. No paid change is authorized.
- VDE Phase 2B exact bytes are unavailable on PC, Library and GitHub; canonical commit/snapshot metadata is preserved.

## New projects

Any new codebase, worker, website, API, marketplace, AI service, automation, payment integration, plugin, or control-plane component is automatically covered by this policy.
