# Ritratto Stellare V2 — deploy candidate
Date: 2026-09-25

Status: backend candidate completed locally; NOT yet deployed.

Artifact:
- Ritratto_Stellare_V2.0.0_FINAL_CANDIDATE_2026-09-25.zip
- SHA-256: c199df173ddaf75c2fa55754b4ba36abde9faecc3141e1ae7f23e853eba9ce50
- ZIP integrity: PASS

QA:
- PHP lint: 20/20 PASS
- JavaScript syntax: PASS
- Public naming: PASS
- Quotas: trial 150 / PEGASO 20 / FENICE 60 / ANDROMEDA 150
- Areas: 4 / 8 / 12
- Trial ANDROMEDA experience: PASS
- Anti-abuse: 4/hour, 10/24h, 150 total
- Direct buy intent: landing -> OTP -> PayPal
- PayPal legacy ORIONE IDs preserved; new level public name FENICE

Deployment gate:
Do not merge the V2 landing to main until the backend candidate is deployed and smoke-tested.
