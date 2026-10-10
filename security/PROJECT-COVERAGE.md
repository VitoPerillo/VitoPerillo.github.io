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
| Vito AI / MR Control Hub | VALIDATED | Private remote repo; blocking Wrangler build gate on main; dry-run PASS; staging live 200 with security headers and production_forbidden=true |
| Chakra Wholeness | DEPLOY GATE INSTALLED | Private remote repo; portable gate PASS + 9/9 tests PASS; Vercel buildCommand now runs gate+tests. New preview validation blocked only by Hobby daily API deployment limit (>100/day); existing production remains READY |
| Gestionale Yoganostress | CANONICAL RC4 + DEPLOY GATE | Canonical RC4 recovered from GitHub artifact 11537672068 and verified byte-for-byte (SHA-256 7f8a4cbd…77931b; 38 plugin files). Private repo yoganostress-gestionale created with provenance, canonical ZIP and 15-control Security Gate PASS. MR Bridge staging workflow on main now uses only canonical RC4 and blocks on SHA/security/PHP lint before deploy; post-deploy verifies runtime + attestation. Current GitHub billing/spending block prevents runner startup, so no post-merge deploy occurred |
| Spiritual Coach | VALIDATED | Private remote repo; Wrangler deploy gate on main; 39/39 Red Team PASS; staging deployed and /api/v1/health returns 200 with production_forbidden=true |
| Yoganostress Commerciale AI | DEPLOY GATE PASS | Private remote repo; Cloudflare build gate on main; consent/privacy tests + Worker security checks + Wrangler dry-run PASS; live /health 200 |
| Vito Prompt / Prompt Coach | SOURCE NOT LOCATED | In scope; no canonical codebase located on current PC/GitHub yet |
| Vito Document Engine | SOURCE NOT LOCATED | In scope; parser/sandbox gate required when canonical codebase is located |

## Infrastructure constraints

- Private GitHub Actions currently do not start because GitHub reports failed recent account payments or a spending-limit requirement. No paid-plan or spending-limit increase is part of this security design.
- Provider-native build/deploy gates are therefore the primary blocking control where available.
- Vercel Hobby preview validation for Chakra is temporarily blocked by the free daily API deployment limit; no plan change is required for the installed build gate to remain configured.

## New projects

Any new codebase, worker, website, API, marketplace, AI service, automation, payment integration, plugin, or control-plane component is automatically covered by this policy.
