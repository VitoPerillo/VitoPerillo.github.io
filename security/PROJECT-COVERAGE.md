# Universal Security Coverage

## Policy

All current and future technical/digital projects are in scope by default.

**Security Gate PASS is not the same as Release PASS.**

A project is release-ready only when its applicable chain is evidenced:

`CANONICAL SOURCE → SECURITY GATE → DEPENDENCIES → STAGING → E2E → RED TEAM → BACKUP/RESTORE → ROLLBACK → RC → PRODUCTION → POST-DEPLOY`

Controls marked N/A must be justified.

See `security/SECURITY-RELEASE-BOARD.md` for the strict evidence matrix and `security/SECURITY-PUBLICATION-ORDER.md` for the exact execution order to publication.

## Current coverage

| Project | Security status | Release status | Notes |
|---|---|---|---|
| Local Autopilot | PASS existing gate | PARTIAL | Canonical release/staging/E2E/restore/rollback evidence not fully reconciled in current board |
| Ritratto Stellare | PASS existing gate | PARTIAL | Existing deploy gate; full release chain not yet evidenced |
| MR Bridge / yoganostress-wordpress-bridge | PASS existing gate | PARTIAL | Staging/OIDC/backup/rollback capabilities exist; release-level restore/rollback exercise still partial |
| Professione Smart | PASS current-main local revalidation | PARTIAL | main b36a02da…: syntax + blocking gate + Wrangler dry-run PASS; isolated local Worker /health 200 and unauthenticated Stripe mobile endpoints 401. Fresh GitHub Security Revalidation run 38528718009 blocked before steps by billing/spending. Real Cloudflare staging is additionally blocked because non-interactive Wrangler has no existing authorized API token for remote D1 schema-only export/create |
| Rivensight | PASS current-main local revalidation | PARTIAL | main 8ad2b80d…: syntax + tests + gate PASS; local /health 200; unauthenticated /automation/run 401; synthetic state backup/restore preserved SHA and post-restore /health 200. Fresh GitHub Security Revalidation run 38528736856 blocked before steps by account billing/spending |
| MR Control Hub | PASS | PARTIAL | main 09afccb7…; Wrangler toolchain audit fixed from 3 HIGH to 0 vulnerabilities; Security Gate + dry-run PASS; staging health/security headers previously validated |
| Chakra Wholeness | PASS remote build gate | PARTIAL | main 3b8feed… remains canonical; dependency-fix branch 688a0bf… has clean venv audit 0 vulnerabilities, 9/9 tests PASS and Vercel preview dpl_GvBMnuBwcDLAnPZkX6LqCo3ezjsi READY. PR #2 intentionally unmerged to avoid possible production auto-deploy |
| Gestionale Yoganostress | RC4 PASS base / RC5 security candidate | PARTIAL | RC4 remains the byte-exact validated rollback baseline. Red Team found a real RC4 P1: staff `session_id` can canonicalize staging toward the production-path `/gestione/`. Existing RC5 candidate `security/rc5-staff-route-isolation` head `be5cacff…` fixes staff session/customer URL isolation and adds a staging-only canonical guard; direct branch-file regression checks PASS 13/13. RC5 package declares SHA256 `86da281d…e791` and rollback RC4 SHA `7f8a4cbd…7931b`, but RC5 is not yet live-staging validated. Production untouched |
| Spiritual Coach | PASS previous gate + Red Team | PARTIAL | 39/39 Red Team and staging health PASS; dependency/restore/rollback/full live E2E incomplete |
| Yoganostress Commerciale AI | PASS previous Cloudflare gate | PARTIAL | Consent/privacy tests + dry-run + health evidence; broader Red Team/restore/rollback incomplete |
| Vito AI | PASS universal gate | PARTIAL | Branch vito-ai-multicloud-rc14; pdfjs-dist upgraded to 6.3.289, npm 0 vulnerabilities; fresh security-gated provider staging deploy/post-deploy still missing |
| Vito Prompt / Prompt Coach | PASS shared Vito AI runtime gate | PARTIAL | Branch vito-ai-coach-staging; passwordless/Guided Discovery/ecosystem/Prompt Red Team PASS; provider staging/restore/rollback incomplete |
| Vito Document Engine | BLOCKED | BLOCKED | Canonical Phase 2B metadata known but exact bytes unavailable; do not downgrade to Phase 2A |
| Vito AutoLoop | N/A — spec only | N/A | No runtime yet; automatically enters gate when implementation starts |

## Current infrastructure blockers

- Fresh GitHub-hosted private-runner revalidation attempts for Professione Smart and Rivensight fail before any step because GitHub reports failed recent account payments or a required spending-limit increase. No paid change is authorized.
- Gestionale Yoganostress RC5 staging is temporarily blocked by deploy-channel availability: WPVibe free quota was exhausted for the current rolling window; the active MR direct deploy client exists but its matching private key was not found in authorized PC locations; staging Application Password environment variables are absent; RC5 private GitHub Actions run failed before executing steps. No new credential or paid bypass was created.\n- VDE Phase 2B exact bytes are unavailable on all currently authorized recovery surfaces (PC, Library/conversation-file index and accessible GitHub repositories); canonical commit/snapshot metadata is preserved in security/VDE-PHASE2B-RECOVERY-EVIDENCE.md.

## New projects

Any new codebase, worker, website, API, marketplace, AI service, automation, payment integration, plugin, or control-plane component is automatically covered by this policy.
