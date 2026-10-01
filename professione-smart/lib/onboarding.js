export const ONBOARDING_STATES = Object.freeze({
  REGISTERED: "registered",
  PROVIDER_SELECTED: "provider_selected",
  PENDING_VERIFICATION: "pending_verification",
  ACTIVE: "active",
  BLOCKED: "blocked"
});

export function normalizeProviderStatus(provider, rawStatus) {
  const p = String(provider || "").toLowerCase();
  const s = String(rawStatus || "").toLowerCase();

  if (!["stripe", "mollie"].includes(p)) {
    throw new Error("Unsupported provider");
  }

  if (p === "stripe") {
    if (["active", "enabled", "card_payments_active"].includes(s)) return ONBOARDING_STATES.ACTIVE;
    if (["blocked", "disabled", "rejected"].includes(s)) return ONBOARDING_STATES.BLOCKED;
    return ONBOARDING_STATES.PENDING_VERIFICATION;
  }

  if (["active", "approved", "enabled"].includes(s)) return ONBOARDING_STATES.ACTIVE;
  if (["blocked", "rejected", "disabled"].includes(s)) return ONBOARDING_STATES.BLOCKED;
  return ONBOARDING_STATES.PENDING_VERIFICATION;
}

export function canEnableTapToPay({ onboardingState, deviceCompatible = false }) {
  return onboardingState === ONBOARDING_STATES.ACTIVE && deviceCompatible === true;
}
