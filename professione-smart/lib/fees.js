export const TEST_PLATFORM_FEE_BPS = 50; // 0.50% - valore provvisorio di test

export function calculatePlatformFee(amountCents, feeBps = TEST_PLATFORM_FEE_BPS) {
  if (!Number.isInteger(amountCents) || amountCents < 0) {
    throw new TypeError("amountCents must be a non-negative integer");
  }
  if (!Number.isInteger(feeBps) || feeBps < 0 || feeBps > 10000) {
    throw new TypeError("feeBps must be an integer between 0 and 10000");
  }
  return Math.round((amountCents * feeBps) / 10000);
}

export function splitPayment(amountCents, feeBps = TEST_PLATFORM_FEE_BPS) {
  const platformFee = calculatePlatformFee(amountCents, feeBps);
  return {
    amountCents,
    platformFeeCents: platformFee,
    merchantGrossCents: amountCents - platformFee,
    feeBps
  };
}
