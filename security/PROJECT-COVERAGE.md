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
| Professione Smart | IN PROGRESS | Dedicated private repo gate branch exists; GitHub Actions currently failing before job steps |
| Rivensight | IN PROGRESS | Dedicated private repo gate branch exists; GitHub Actions currently failing before job steps |
| Vito AI / MR Control Hub | TESTED LOCALLY + STAGING HARDENED | Security headers validated on staging; local canonical Git commit exists; remote private repo still required |
| Chakra Wholeness | TESTED LOCALLY | 9/9 tests PASS; local security-gate commit exists; remote private repo still required |
| Gestionale Yoganostress | PARTIAL | Strong application security already verified locally; canonical remote repo still required |
| Vito Prompt / Prompt Coach / spiritual coach | TODO | Must inherit the universal gate before public launch |
| Vito Document Engine | TODO | Parser/sandbox-specific gate required; do not disturb frozen phase baselines |
| Yoganostress Commerciale AI | TODO | Must inherit the universal gate before public launch |

## New projects

Any new codebase, worker, website, API, marketplace, AI service, automation, payment integration, plugin, or control-plane component is automatically covered by this policy.
