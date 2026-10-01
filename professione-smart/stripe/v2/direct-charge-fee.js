import { calculatePlatformFee } from "../../lib/fees.js";

/**
 * Parameters added when Professione Smart creates a direct payment
 * on behalf of a connected professional.
 *
 * amountCents: total paid by the end customer.
 * application_fee_amount: Professione Smart revenue.
 */
export function buildDirectChargeFee({ amountCents, feeBps = 50 }) {
  return {
    amount: amountCents,
    currency: "eur",
    application_fee_amount: calculatePlatformFee(amountCents, feeBps)
  };
}
