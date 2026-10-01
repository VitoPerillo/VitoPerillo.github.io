import { calculatePlatformFee } from "../../lib/fees.js";

/**
 * Server-side contract for a Stripe Terminal direct-charge PaymentIntent.
 * The mobile client never decides the final platform fee or connected account.
 */
export function buildTerminalPayment({
  amountCents,
  connectedAccountId,
  feeBps = 50,
  currency = "eur"
}) {
  if (!connectedAccountId?.startsWith("acct_")) {
    throw new Error("Invalid connected account");
  }
  if (!Number.isInteger(amountCents) || amountCents <= 0) {
    throw new Error("Invalid amount");
  }
  if (currency !== "eur") {
    throw new Error("Italy Terminal launch supports EUR only");
  }

  return {
    connectedAccountId,
    paymentIntent: {
      amount: amountCents,
      currency,
      payment_method_types: ["card_present"],
      application_fee_amount: calculatePlatformFee(amountCents, feeBps),
      metadata: {
        professione_smart: "1",
        channel: "tap_to_pay"
      }
    }
  };
}
