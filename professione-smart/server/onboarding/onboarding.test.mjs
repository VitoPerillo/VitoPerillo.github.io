import assert from "node:assert/strict";
import {
  ONBOARDING_STATES,
  normalizeProviderStatus,
  canEnableTapToPay
} from "../../lib/onboarding.js";
import {
  validateRegistration,
  publicRegistrationView
} from "./registration.js";

assert.equal(normalizeProviderStatus("stripe", "active"), ONBOARDING_STATES.ACTIVE);
assert.equal(normalizeProviderStatus("stripe", "pending"), ONBOARDING_STATES.PENDING_VERIFICATION);
assert.equal(normalizeProviderStatus("mollie", "approved"), ONBOARDING_STATES.ACTIVE);
assert.equal(normalizeProviderStatus("mollie", "rejected"), ONBOARDING_STATES.BLOCKED);

assert.equal(canEnableTapToPay({ onboardingState: ONBOARDING_STATES.ACTIVE, deviceCompatible: true }), true);
assert.equal(canEnableTapToPay({ onboardingState: ONBOARDING_STATES.ACTIVE, deviceCompatible: false }), false);
assert.equal(canEnableTapToPay({ onboardingState: ONBOARDING_STATES.PENDING_VERIFICATION, deviceCompatible: true }), false);

const reg = validateRegistration({
  businessName: "Studio Test",
  email: "PROVA@example.com",
  phone: "+39 333 0000000",
  provider: "stripe"
});
assert.equal(reg.email, "prova@example.com");
assert.equal(reg.onboardingState, ONBOARDING_STATES.PROVIDER_SELECTED);

const publicView = publicRegistrationView({ ...reg, secret: "never expose", tapToPayEnabled: false });
assert.equal("secret" in publicView, false);

console.log("Professione Smart onboarding tests: PASS");
