# Mollie staging preflight — 2026-10-08

Status: READY_FOR_SECRET / E2E BLOCKED ONLY BY MOLLIE_API_KEY.

Static/Red-Team gates completed on branch ritratto-mollie-staging:
- canonical paid plans: PEGASO 6.90/69, FENICE 9.90/99, ANDROMEDA 14.90/149 EUR;
- trial is trial_andromeda, 30 days, no card, no automatic renewal;
- no ORIONE in new D1 plan constraint;
- checkout rejects unknown plan/cadence and unknown profile;
- live environment is denied unless LIVE_COMMERCIAL_AUTHORIZED=true;
- Mollie API key is runtime-only;
- first payment amount/currency is revalidated server-side before activation;
- duplicate first-payment webhook is idempotent through receipts.transaction_id UNIQUE;
- active subscription is not recreated by a duplicate first-payment webhook;
- renewal webhook records receipts idempotently and updates payment status;
- failed/expired/canceled recurring payment is recorded;
- cancellation is idempotent;
- Mollie customer is reused instead of creating a new customer on every checkout;
- pending payment id is persisted for audit/correlation;
- residual PayPal checkout/UI identifiers removed from canonical public path touched by staging.

Not claimed PASS until a real Mollie TEST E2E proves:
checkout -> first test payment -> webhook -> subscription activation -> recurring test event/payment -> cancellation -> final state.

No merge. LIVE remains disabled.
