/**
 * Stripe Terminal server adapter for Professione Smart.
 * TEST/SANDBOX FIRST. Never expose STRIPE_SECRET_KEY to the client.
 */

function assertAccount(id) {
  if (!/^acct_[A-Za-z0-9]+$/.test(id || "")) throw new Error("Invalid connected account");
}

async function stripePost(path, { secretKey, connectedAccountId, body = {} }) {
  if (!secretKey) throw new Error("Missing Stripe secret");
  assertAccount(connectedAccountId);

  const form = new URLSearchParams();
  for (const [key, value] of Object.entries(body)) {
    if (value === undefined || value === null) continue;
    if (Array.isArray(value)) {
      value.forEach(v => form.append(key, String(v)));
    } else {
      form.append(key, String(value));
    }
  }

  const response = await fetch(`https://api.stripe.com${path}`, {
    method: "POST",
    headers: {
      Authorization: `Bearer ${secretKey}`,
      "Stripe-Account": connectedAccountId,
      "Content-Type": "application/x-www-form-urlencoded"
    },
    body: form
  });

  const data = await response.json();
  if (!response.ok) {
    const message = data?.error?.message || `Stripe HTTP ${response.status}`;
    throw new Error(message);
  }
  return data;
}

export async function createTerminalLocation({
  secretKey,
  connectedAccountId,
  displayName,
  address
}) {
  if (!address?.line1 || !address?.city || !address?.postal_code) {
    throw new Error("Complete Italian Terminal address required");
  }

  return stripePost("/v1/terminal/locations", {
    secretKey,
    connectedAccountId,
    body: {
      display_name: displayName || "Professione Smart POS",
      "address[line1]": address.line1,
      "address[line2]": address.line2 || "",
      "address[city]": address.city,
      "address[state]": address.state || "",
      "address[postal_code]": address.postal_code,
      "address[country]": "IT"
    }
  });
}

export async function createConnectionToken({
  secretKey,
  connectedAccountId,
  locationId
}) {
  return stripePost("/v1/terminal/connection_tokens", {
    secretKey,
    connectedAccountId,
    body: locationId ? { location: locationId } : {}
  });
}

export async function createTerminalPaymentIntent({
  secretKey,
  connectedAccountId,
  amountCents,
  applicationFeeCents
}) {
  if (!Number.isInteger(amountCents) || amountCents <= 0) throw new Error("Invalid amount");
  if (!Number.isInteger(applicationFeeCents) || applicationFeeCents <= 0 || applicationFeeCents >= amountCents) {
    throw new Error("Invalid application fee");
  }

  return stripePost("/v1/payment_intents", {
    secretKey,
    connectedAccountId,
    body: {
      amount: amountCents,
      currency: "eur",
      "payment_method_types[]": ["card_present"],
      application_fee_amount: applicationFeeCents,
      "metadata[professionesmart]": "1",
      "metadata[channel]": "tap_to_pay"
    }
  });
}
