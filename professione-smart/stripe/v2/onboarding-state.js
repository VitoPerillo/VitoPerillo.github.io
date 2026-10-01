/**
 * UI state used by Professione Smart for a connected professional.
 * No payment can be accepted until card payments are active.
 */
export function connectedAccountState(account) {
  const status =
    account?.configuration?.merchant?.capabilities?.card_payments?.status ??
    "unknown";

  return {
    accountId: account?.id ?? null,
    cardPaymentsStatus: status,
    canAcceptPayments: status === "active",
    needsOnboarding: status !== "active"
  };
}
