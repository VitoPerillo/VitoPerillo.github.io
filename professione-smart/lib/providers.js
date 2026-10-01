export const PAYMENT_PROVIDERS = Object.freeze({
  mollie: {
    id: "mollie",
    label: "Mollie",
    enabled: true,
    status: "pending_approval",
    supportsTapToPay: true,
    supportsPlatformFee: true
  },
  stripe: {
    id: "stripe",
    label: "Stripe",
    enabled: true,
    status: "test",
    supportsTapToPay: true,
    supportsPlatformFee: true
  }
});

export const DEFAULT_PROVIDER = "stripe";

export function getProvider(id) {
  const provider = PAYMENT_PROVIDERS[id];
  if (!provider || !provider.enabled) {
    throw new Error("Unsupported payment provider");
  }
  return provider;
}
