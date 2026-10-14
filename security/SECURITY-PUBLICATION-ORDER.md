# Security Publication Order

Updated: 2026-10-14

This file is the execution order from current state to publication.  
Do not skip a gate and do not change project order merely because a later project exposes an easier isolated task.

## Global release rule

For every project:

`CANONICAL → SECURITY → DEPENDENCIES → STAGING → E2E → RED TEAM → BACKUP/RESTORE → ROLLBACK → RC → PRODUCTION → POST-DEPLOY`

Production is allowed only when every applicable prior gate is PASS.

## 1. Professione Smart

Current:
- Canonical main confirmed.
- Security Gate PASS on current main.
- Wrangler dry-run PASS.
- Local isolated Worker PASS.
- Unauthenticated Stripe mobile endpoints correctly return 401.
- GitHub-hosted Security Revalidation blocked pre-job by account billing/spending.
- Real Cloudflare staging blocked because no existing authorized non-interactive Cloudflare API token is available for remote D1 schema-only export/create.

Exact path:
1. Obtain/reuse an already-authorized Cloudflare API credential without adding fixed cost.
2. Export production D1 **schema only**, no customer data.
3. Create separate D1 staging.
4. Import schema into staging.
5. Deploy separate `professione-smart-staging` Worker.
6. Run Stripe/Mollie sandbox/onboarding/payment E2E.
7. Run negative auth/webhook/replay/rate-limit tests.
8. Exercise D1 staging backup + restore.
9. Exercise Worker/D1 rollback.
10. Freeze RC with commit + config + rollback point.
11. Production deploy only after explicit GO.
12. Post-deploy payment smoke + logs + rollback readiness.

## 2. Rivensight

Current:
- Canonical main confirmed.
- Syntax + tests + Security Gate PASS.
- Local E2E /health 200.
- Unauthorized /automation/run returns 401.
- Synthetic backup/restore preserves exact SHA and post-restore /health 200.
- GitHub-hosted Security Revalidation blocked pre-job by billing/spending.
- No isolated provider staging currently evidenced.

Exact path:
1. Establish disposable provider staging without changing production.
2. Run monitoring cycle against test targets only.
3. Run auth negative tests and automation-secret replay/invalid-secret cases.
4. Exercise provider persistent-volume backup + restore.
5. Exercise provider rollback to previous image/commit.
6. Freeze Docker/commit RC.
7. Production deploy only after GO.
8. Post-deploy /health + one authorized automation smoke + log review.

## 3. Gestionale Yoganostress

Current:
- RC4 byte-exact remains the validated base and rollback target.
- Red Team on current RC4 found a real P1: staff URL `?session_id=` can canonicalize from staging to the production-path `/gestione/`; no redirect was followed and production was not modified.
- Negative live checks on RC4 PASS: unauthenticated staff page exposes login only; unauthenticated mark-paid/cancel return 403; fixture state remained DUE / BOOKED / 7 credits.
- Existing candidate `security/rc5-staff-route-isolation` head `be5cacff98a713eab4653d023c6c16aef3c4a6b7` supersedes RC4 for release work.
- RC5 is `0.4.27-GO-LIVE-MINIMUM-RC5`, switches staff navigation to `yns_staff_session` / `yns_staff_customer`, adds a staging-only canonical guard, includes regression test, package manifest SHA `86da281de9979b5b17171de8daa16af8646166a88a38c645357249c27a70e791`, and explicit RC4 rollback SHA `7f8a4cbdb1f91c2c41b792c4c55761c1d52cef16d269d5ac296a07a65277931b`.
- Direct branch-file regression verification PASS 13/13.
- RC5 is not yet staging-validated.
- GitHub private runner failed before any step; WPVibe quota is exhausted for the current rolling window; the active MR direct deploy client exists but its matching private key is not recoverable from the authorized PC locations checked; no staging Application Password is present.
- Production remains untouched.

Exact path:
1. Do not return to RC4 as release candidate; keep RC4 only as rollback baseline.
2. Reuse RC5 `security/rc5-staff-route-isolation`; do not duplicate it with another branch.
3. Restore an already-authorized staging deploy channel at €0 (WPVibe quota rollover, existing MR direct private key if recovered, or existing GitHub runner once billing block clears). Do not create new credentials solely to bypass the gate.
4. Independently verify the RC5 package SHA before deploy.
5. Deploy RC5 to staging only through MR Bridge with pre-deploy backup.
6. Run staff login → Agenda → session → participant → customer → carnet → DUE→PAID → cancel → CANCELLED → credit 7→8 → logout.
7. Complete negative security gate: invalid/no session, capability denial, CSRF/nonce failure, replay, forged/expired staff session, unauthorized mutation, and ensure state unchanged on every rejected request.
8. Create and verify a staging data backup; record backup ID/checksum.
9. Execute real staging restore drill and health/audit-chain verification.
10. Execute rollback drill to byte-exact RC4, verify health/state, then redeploy RC5 and re-run the critical smoke.
11. Remove/neutralize QA fixture and synthetic staff session/account used for this final gate.
12. Freeze one RC5 release record: commit, ZIP SHA-256, MR Bridge attestation/tree hash, backup ID, rollback artifact/SHA.
13. Production deploy only after every applicable Board column is PASS.
14. Post-deploy health, staff login, one booking/cancel/payment-state smoke, audit verification and rollback readiness.

## 4. Chakra Wholeness

Current:
- Security Gate PASS.
- Main preview READY.
- Main build/test dependency pins include known vulnerabilities.
- Fix branch `security/dependency-audit-fix` is validated: clean venv audit 0 vulnerabilities, 9/9 tests PASS, Vercel preview READY.
- Fix intentionally not merged to avoid possible production auto-deploy.

Exact path:
1. Confirm main merge cannot auto-promote to production, or temporarily disable production auto-deploy.
2. Merge dependency fix PR #2.
3. Create fresh preview from merged main.
4. Run full account/auth/offer/booking E2E on preview.
5. Run object-level authorization + capacity/booking abuse Red Team.
6. Exercise Neon backup/restore on non-production database.
7. Exercise Vercel rollback/redeploy of previous preview artifact.
8. Freeze RC commit + DB schema + env contract.
9. Production deploy after GO.
10. Post-deploy auth/booking/health/error-log smoke.

## 5. Vito AI / Vito Prompt / Prompt Coach

Current:
- Canonical branches identified.
- Universal Security Gate PASS.
- Vito AI dependency audit fixed; npm reports 0 vulnerabilities on validated branch.
- Prompt hard Red Team, tenant isolation, session rotation, high-risk guard and Coach isolation PASS.
- Render autoDeploy remains off.

Exact path:
1. Deploy security-gated Vito AI staging branch.
2. Deploy security-gated Coach/Prompt staging branch.
3. Run live auth/session/memory isolation E2E.
4. Run Prompt injection/tool/exfiltration/document-malware Red Team.
5. Run provider quota/failover/denial-of-wallet tests.
6. Exercise persistent data backup + restore.
7. Exercise rollback to previous staging commit.
8. Freeze separate RCs for Vito AI and Coach/Prompt runtime.
9. Production deploy only after all live gates.
10. Post-deploy health, auth, memory isolation, tool and AutoCheck smoke.

## 6. Spiritual Coach

Current:
- Security Gate PASS.
- 39/39 Red Team PASS.
- Staging /api/v1/health 200.
- No package dependencies.

Exact path:
1. Run live staging conversation E2E.
2. Re-run only live-only safety/memory/isolation cases not covered by local Red Team.
3. Exercise D1 backup + restore.
4. Exercise Worker rollback.
5. Freeze RC.
6. Production deploy after GO.
7. Post-deploy health + memory opt-out + safety smoke.

## 7. Yoganostress Commerciale AI

Current:
- Cloudflare gate PASS.
- Consent/privacy tests PASS.
- Health 200.
- No package-managed dependencies.

Exact path:
1. Establish/confirm isolated staging identity.
2. Run inbound opt-in / no-opt-in / do-not-contact / callback E2E.
3. Run admin/auth/webhook negative tests.
4. Exercise D1 backup + restore.
5. Exercise rollback.
6. Freeze RC.
7. Production deploy after GO.
8. Post-deploy consent/privacy smoke and log review.

## 8. MR Control Hub

Current:
- Canonical main now includes audited Wrangler lock.
- npm audit: 0 vulnerabilities.
- Security Gate PASS.
- Wrangler dry-run PASS.
- Staging health/security headers previously validated.
- PRODUCTION_FORBIDDEN=true.

Exact path:
1. Re-run staging auth/replay/scope negative E2E on current main.
2. Exercise D1 backup + restore.
3. Exercise Worker rollback.
4. Freeze RC.
5. Keep production forbidden until explicit architecture GO.
6. Post-deploy staging health and audit-chain verification.

## 9. MR Bridge

Current:
- Dedicated security gate exists.
- OIDC/staging/deploy/backup/rollback capabilities exist.

Exact path:
1. Reconcile live installed version with canonical repository.
2. Run read-only capability/auth checks.
3. Exercise backup + restore + rollback on staging.
4. Verify audit hash chain after recovery.
5. Freeze infrastructure RC.
6. Production maintenance release only after downstream projects no longer depend on a moving bridge baseline.
7. Post-deploy /status, /plugins, health and audit verification.

## 10. Local Autopilot

Exact path:
1. Reconcile canonical commit with deployed Worker.
2. Preserve already-valid shared security gate.
3. Close current dependency scan.
4. Run isolated staging/auth E2E.
5. Exercise data backup/restore and rollback.
6. Freeze RC.
7. Production deploy after GO.
8. Post-deploy auth/session/health smoke.

## 11. Ritratto Stellare

Exact path:
1. Reconcile canonical commit with deployed Worker/site.
2. Preserve existing deploy security gate.
3. Run dependency scan.
4. Run PayPal Sandbox E2E and negative webhook/auth cases.
5. Exercise persistence backup/restore and rollback.
6. Freeze RC.
7. Production deploy after GO.
8. Post-deploy checkout/health/log smoke.

## 12. Vito Document Engine

Current:
- Original FASE 2B metadata is known.
- Exact Phase 2B bytes are not recoverable from currently authorized PC, Library/conversation-file index or GitHub.
- Phase 2A/1F must not be promoted as Phase 2B.

Exact path:
1. Keep original Phase 2B metadata immutable.
2. If a new historical storage source appears, recover bytes and verify exact SHA-256.
3. Otherwise create a **new separately named reconstructed baseline**, never calling it the original Phase 2B.
4. Re-implement only from documented Phase 2B capability contract.
5. Run parser sandbox, hostile-file, extraction-limit, path traversal, OCR, spreadsheet, provenance and fail-closed gates.
6. Prove functional equivalence to documented Phase 2B outputs.
7. Create canonical private repo and RC.
8. Deploy staging.
9. Run E2E + Red Team + backup/restore + rollback.
10. Only then allow production.

## 13. Vito AutoLoop

Current: specification only.

Exact path when implementation begins:
1. Create canonical private repository before first deploy.
2. Attach universal Security Gate immediately.
3. No live automation until auth/tool allowlist/audit/rollback are PASS.
4. Continue through the standard release chain.
