# YOGANOSTRESS ADESSO — zero single point of failure pipeline

Primary path:

GitHub source → PHP/JS/JSON static QA → mandatory Playwright Dummy Gate (mobile + desktop) → deterministic ZIP → SHA-256 digest + GitHub provenance attestation → direct Plugin Bridge REST → staging → mandatory post-deploy Dummy Gate → direct plugin verification.

If the post-deploy Dummy Gate fails, the workflow automatically calls Plugin Bridge rollback using the backup created by the atomic deploy.

WPVibe and TinyFish are not in the primary path. They remain emergency/fallback tools only.

## One-time GitHub secrets needed for direct staging deploy

- `YSAD_STAGING_URL` — `https://www.yoganostress.it/staging-gestionale`
- `YSAD_STAGING_USER` — WordPress administrator allowed by Plugin Bridge
- `YSAD_STAGING_APP_PASSWORD` — dedicated WordPress Application Password

The QA/build job needs no WordPress credential. Deploy is fail-closed if any credential is absent.

## Gates

A build cannot be packaged unless:
- every PHP file passes `php -l`;
- every JS file passes `node --check`;
- every JSON file parses;
- Dummy Test passes on 390×844 mobile and 1440×1000 desktop;
- no browser console errors are observed;
- no horizontal overflow occurs;
- a zero-knowledge user can complete: entry → 5-question check-in → safety gate → guided 4-phase practice → feedback → Direct/Replay → Map → Gift → Profile → Plans.

A staging deployment cannot remain installed if the post-deploy Dummy Gate fails: rollback is automatic.
