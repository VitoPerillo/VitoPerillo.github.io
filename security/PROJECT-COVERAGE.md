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
| Professione Smart | IN PROGRESS | Dedicated private repo gate branch exists; GitHub Actions fail before any job step, so not merged |
| Rivensight | IN PROGRESS | Dedicated private repo gate branch exists; GitHub Actions fail before any job step, so not merged |
| Vito AI / MR Control Hub | TESTED + STAGING HARDENED | Staging headers validated live; local canonical Git commit e7e5eb3; remote private repo still required |
| Chakra Wholeness | TESTED LOCALLY | 9/9 tests PASS; local security-gate commit 823c1fe; remote private repo still required |
| Gestionale Yoganostress | PARTIAL | Strong application security verified locally; canonical remote repo still required |
| Spiritual Coach | TESTED LOCALLY | Security Gate PASS + 39/39 Red Team PASS; local canonical commit d858fec; remote private repo still required |
| Yoganostress Commerciale AI | TESTED LOCALLY | Consent/privacy Security Gate PASS; local canonical commit e1b7cbe; remote private repo still required |
| Vito Prompt / Prompt Coach | SOURCE NOT LOCATED | In scope; no canonical codebase located on current PC/GitHub yet |
| Vito Document Engine | SOURCE NOT LOCATED | In scope; parser/sandbox gate required when canonical codebase is located |

## New projects

Any new codebase, worker, website, API, marketplace, AI service, automation, payment integration, plugin, or control-plane component is automatically covered by this policy.
