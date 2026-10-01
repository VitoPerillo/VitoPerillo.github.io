import assert from "node:assert/strict";
import { buildTerminalPayment } from "./terminal-payment-contract.js";

const p = buildTerminalPayment({
  amountCents: 10000,
  connectedAccountId: "acct_test_professionista"
});

assert.equal(p.paymentIntent.amount, 10000);
assert.equal(p.paymentIntent.application_fee_amount, 50);
assert.equal(p.paymentIntent.currency, "eur");
assert.deepEqual(p.paymentIntent.payment_method_types, ["card_present"]);
assert.equal(p.paymentIntent.metadata.channel, "tap_to_pay");

console.log("Professione Smart Terminal contract: PASS");
