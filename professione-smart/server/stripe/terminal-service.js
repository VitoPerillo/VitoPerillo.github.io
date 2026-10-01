import { calculatePlatformFee } from "../../lib/fees.js";
import {
  createConnectionToken,
  createTerminalLocation,
  createTerminalPaymentIntent
} from "./terminal-api.js";

/**
 * Domain service.
 * IMPORTANT: connectedAccountId must come from the authenticated professional's
 * server-side record. Never trust an account id submitted by the mobile client.
 */

export async function provisionProfessionalTerminal({
  secretKey,
  professional,
  address
}) {
  if (!professional?.stripeConnectedAccountId) throw new Error("Professional is not connected to Stripe");

  const location = await createTerminalLocation({
    secretKey,
    connectedAccountId: professional.stripeConnectedAccountId,
    displayName: professional.businessName || "Professione Smart POS",
    address
  });

  return {
    provider: "stripe",
    connectedAccountId: professional.stripeConnectedAccountId,
    locationId: location.id
  };
}

export async function issueTerminalConnectionToken({
  secretKey,
  professional,
  locationId
}) {
  const token = await createConnectionToken({
    secretKey,
    connectedAccountId: professional.stripeConnectedAccountId,
    locationId
  });

  return { secret: token.secret };
}

export async function startTapToPayPayment({
  secretKey,
  professional,
  amountCents,
  feeBps = 50
}) {
  const applicationFeeCents = calculatePlatformFee(amountCents, feeBps);

  const paymentIntent = await createTerminalPaymentIntent({
    secretKey,
    connectedAccountId: professional.stripeConnectedAccountId,
    amountCents,
    applicationFeeCents
  });

  return {
    paymentIntentId: paymentIntent.id,
    clientSecret: paymentIntent.client_secret,
    amountCents,
    applicationFeeCents,
    connectedAccountId: professional.stripeConnectedAccountId
  };
}
