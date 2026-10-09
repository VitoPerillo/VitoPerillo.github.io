# Universal Security + Compliance Coverage

## Policy

All current and future technical/digital projects are in scope by default.

A project cannot be considered release-ready unless:
1. its applicable Security Gate is PASS or an explicit documented exception exists;
2. its Vito Compliance project gate is PASS for the intended release profile;
3. the repository is mapped in the central repository inventory.

Unknown repository/project = **BLOCKED**.

## Current repository coverage — 9 October 2026

| Repository / project | Security / compliance status | Notes |
|---|---|---|
| VitoPerillo.github.io / Local Autopilot | SECURITY PASS · COMPLIANCE REVIEW | Privacy/Terms/editorial rules added on compliance branch; UGC human-review; ads TEST €0 |
| VitoPerillo.github.io / Ritratto Stellare | SECURITY PASS · PUBLIC BLOCKED | Legal pack exists; PayPal LIVE off; privacy self-service hard-stop until auth/export/delete |
| yoganostress-wordpress-bridge / MR Bridge | SECURITY STRONG · INTERNAL REVIEW | OIDC/Ed25519/Vault/hash-chain; 180-day audit retention patch on separate branch |
| yoganostress-wordpress-bridge / Vito Prompt + Prompt Coach | SECURITY INHERITED · PUBLIC BLOCKED | AI transparency/18+ added; Coach non-therapy/autonomy boundary added |
| professione-smart | SECURITY BASELINE · PUBLIC BLOCKED | Privacy/Terms/retention/DSAR/provider register structurally present; operator/provider binding remains |
| chakra-wholeness | SECURITY BASELINE · COMPLIANCE 12/12 LOCAL PASS | Marketplace/operator/DSA/P2B gates still open |
| vito-ai-control-hub | INTERNAL STAGING PASS | production forbidden; encrypted vault; 180-day audit and 30-day inactive-manifest retention tested |
| spiritual-coach | STAGING SAFETY PASS · PUBLIC BLOCKED | Safety/CoachCheck/private session/export/delete present; public legal/18+ gates remain |
| yoganostress-commerciale-ai | SECURITY PASS · COMPLIANCE REVIEW | Inbound Messenger no longer equals marketing consent; regression tests PASS |
| yoganostress-gestionale | SECURITY PASS · COMPLIANCE REVIEW | Certificate document not stored; status/expiry only; formal health-data legal basis/retention remains |
| rivensight | SECURITY BASELINE · PUBLIC BLOCKED | SSRF protection/secret guards present; B2B legal/privacy/source-rights gates remain |

## Verified cross-project checks

- Central repository coverage: **PASS — 9 repositories, 17 registered projects, 0 mapping problems**.
- Yoganostress Commerciale AI: core test PASS + Cloudflare Security Gate PASS after consent separation.
- Vito AI Control Hub: retention regression PASS.
- Chakra Wholeness: local compliance suite 12/12 PASS.
- New/unknown projects remain fail-closed by policy.

## New projects

Any new codebase, worker, website, API, marketplace, AI service, automation, payment integration, plugin, or control-plane component is automatically in scope.

Before public or paid launch it must:
- be added to the central project registry;
- be mapped in the repository inventory;
- declare operator/release profile;
- pass applicable Security + Compliance gates;
- reopen the gate whenever data, providers, AI purpose, audience, payments, UGC, ads or business model materially change.
