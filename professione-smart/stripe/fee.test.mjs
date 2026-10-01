import assert from "node:assert/strict";
import { calculatePlatformFee, splitPayment } from "../lib/fees.js";

assert.equal(calculatePlatformFee(10000), 50);       // 100,00 € -> 0,50 €
assert.equal(calculatePlatformFee(1000000), 5000);  // 10.000,00 € -> 50,00 €

assert.deepEqual(splitPayment(10000), {
  amountCents: 10000,
  platformFeeCents: 50,
  merchantGrossCents: 9950,
  feeBps: 50
});

console.log("Professione Smart fee tests: PASS");
