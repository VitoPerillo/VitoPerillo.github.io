import { ONBOARDING_STATES } from "../../lib/onboarding.js";
import { getProvider } from "../../lib/providers.js";

function cleanText(value, max = 160) {
  return String(value || "").trim().slice(0, max);
}

export function validateRegistration(input = {}) {
  const businessName = cleanText(input.businessName);
  const email = cleanText(input.email, 254).toLowerCase();
  const phone = cleanText(input.phone, 40);
  const provider = cleanText(input.provider || "stripe", 20).toLowerCase();

  if (!businessName) throw new Error("Business name is required");
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) throw new Error("Valid email is required");
  getProvider(provider);

  return {
    businessName,
    email,
    phone,
    provider,
    onboardingState: ONBOARDING_STATES.PROVIDER_SELECTED
  };
}

export function publicRegistrationView(record) {
  return {
    businessName: record.businessName,
    email: record.email,
    provider: record.provider,
    onboardingState: record.onboardingState,
    tapToPayEnabled: Boolean(record.tapToPayEnabled)
  };
}
