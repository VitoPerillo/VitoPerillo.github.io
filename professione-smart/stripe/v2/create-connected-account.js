/**
 * Professione Smart — Stripe Connect Accounts v2
 * TEST/SANDBOX ONLY until go-live gate is green.
 *
 * The connected professional is the merchant of record.
 * Stripe collects its own processing fees and manages losses.
 * Professione Smart monetizes direct charges using application fees.
 */

export function buildConnectedAccountPayload({ contactEmail, country = "IT" }) {
  if (!contactEmail) throw new Error("contactEmail is required");

  return {
    contact_email: contactEmail,
    dashboard: "full",
    identity: { country },
    defaults: {
      responsibilities: {
        fees_collector: "stripe",
        losses_collector: "stripe"
      }
    },
    configuration: {
      merchant: {
        capabilities: {
          card_payments: { requested: true }
        }
      }
    }
  };
}
