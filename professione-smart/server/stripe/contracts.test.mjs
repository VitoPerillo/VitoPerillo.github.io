import assert from "node:assert/strict";
import { calculatePlatformFee } from "../../lib/fees.js";

assert.equal(calculatePlatformFee(10000, 50), 50);
assert.equal(calculatePlatformFee(2500, 50), 13);
assert.equal(calculatePlatformFee(1000000, 50), 5000);

console.log("Stripe Terminal server contracts: PASS");
