# Universal Security Coverage

## Policy

All current and future technical/digital projects are in scope by default.

A project cannot be considered release-ready unless its applicable Security Gate is PASS or an explicit, documented exception exists.

The shared baseline is adapted per architecture; controls marked N/A must be justified.

## Current coverage

| Project | Security status | Notes |
|---|---|---|
| VitoPerillo.github.io / Local Autopilot | PASS | Shared gate on main |
| Ritratto Stellare | PASS | Gate integrated before existing deploy workflows |
| MR Bridge / yoganostress-wordpress-bridge | PASS | Dedicated PHP/WordPress gate on main |
| Professione Smart | DEPLOY GATE PASS | Private remote repo; blocking gate merged on main; Wrangler dry-run PASS. GitHub-hosted Actions unavailable because account billing/spending blocks runner startup |
| Rivensight | DEPLOY GATE PASS | Private remote repo; Docker build gate merged on main; exact RUN commands syntax+tests+gate PASS; live /health 200. Full local image build not run because Docker is not installed |
| MR Control Hub | VALIDATED | Private remote repo vito-ai-control-hub; blocking Wrangler build gate on main; dry-run PASS; staging live 200 with security headers and production_forbidden=true |
| Chakra Wholeness | VALIDATED | Private remote repo; portable Security Gate PASS + 9/9 local tests PASS. Fresh Vercel preview dpl_2Y9nKSAgiPfAbZLBxpz7NJqGTmWk built from main and reached READY; remote build logs show all Django security checks PASS. Production was not changed |
| Gestionale Yoganostress | CANONICAL RC4 + DEPLOY GATE | Canonical RC4 recovered from GitHub artifact 11537672068 and verified byte-for-byte (SHA-256 7f8a4cbd…77931b; 38 plugin files). Private repo yoganostress-gestionale created with provenance, canonical ZIP and 15-control Security Gate PASS. MR Bridge staging workflow on main now uses only canonical RC4 and blocks on SHA/security/PHP lint before deploy; post-deploy verifies runtime + attestation. Current GitHub billing/spending block prevents runner startup, so no post-merge deploy occurred |
| Spiritual Coach | VALIDATED | Private remote repo; Wrangler deploy gate on main; 39/39 Red Team PASS; staging deployed and /api/v1/health returns 200 with production_forbidden=true |
| Yoganostress Commerciale AI | DEPLOY GATE PASS | Private remote repo; Cloudflare build gate on main; consent/privacy tests + Worker security checks + Wrangler dry-run PASS; live /health 200 |
| Vito AI / Vito Prompt / Prompt Coach | DEPLOY GATE PASS | Canonical source confirmed in yoganostress-wordpress-bridge branches vito-ai-multicloud-rc14 and vito-ai-coach-staging. They share the same Vito AI runtime. Universal security gate is now part of each Render build while autoDeployTrigger remains off. Multicloud gate PASS with tenant isolation, session rotation, high-risk guard, Prompt hard red-team, legal acceptance, Connected Apps/GitHub least privilege; pdfjs-dist upgraded from vulnerable 5.7.284 to 6.3.289 and npm now reports 0 vulnerabilities. Coach/Prompt gate PASS with passwordless auth, Guided Discovery, ecosystem isolation and Prompt hard red-team |
| Vito Document Engine | BLOCKED: CANONICAL PHASE 2B BYTES UNAVAILABLE | Security baseline evidence exists, including Parser Sandbox and hostile-file/isolation gates. Canonical Phase 2B metadata is known: commit aef9b180a190c273b0e74a320cb88eff5b0e5af4, snapshot vito-document-engine-phase2b-final-pass.zip, SHA-256 78ba8e0d8802720efa210e43aa0581825de4d1a6694753c120222d60d5c7578a. Exact Phase 2B bytes are not currently present on PC, Library or GitHub; only older 2A/1F snapshots remain. Do not downgrade the canonical baseline |

## Infrastructure constraints

- Private GitHub Actions currently do not start because GitHub reports failed recent account payments or a spending-limit requirement. No paid-plan or spending-limit increase is part of this security design.
- Provider-native build/deploy gates are therefore the primary blocking control where available.
- Vercel Hobby preview validation for Chakra is temporarily blocked by the free daily API deployment limit; no plan change is required for the installed build gate to remain configured.

## Spec-only projects

- Vito AutoLoop is currently a frozen specification, not a deployed codebase/runtime. It is in scope automatically when implementation starts; no runtime gate can be attached before code exists.

## New projects

Any new codebase, worker, website, API, marketplace, AI service, automation, payment integration, plugin, or control-plane component is automatically covered by this policy.
