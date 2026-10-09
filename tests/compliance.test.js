import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

const read=p=>fs.readFileSync(new URL("../"+p,import.meta.url),"utf8");
const index=read("src/index.js");
const pages=read("src/render/pages.js");
const ads=read("public/pubblicita.html");
const submit=read("public/segnala.html");
const migration=read("migrations/0003_safe_ugc_ads_test.sql");
const privacy=read("public/privacy.html");
const terms=read("public/termini.html");
const editorial=read("public/regole-editoriali.html");

test("Local Autopilot compliance baseline is fail-safe",()=>{
  for(const route of ["/privacy","/termini","/regole-editoriali"])assert.ok(index.includes(route));
  assert.ok(pages.includes('href="/privacy"'));
  assert.ok(pages.includes('href="/termini"'));
  assert.ok(pages.includes('href="/regole-editoriali"'));
  assert.ok(submit.includes('name="rights_declared"'));
  assert.ok(ads.includes('name="rights_declared"'));
  assert.ok(ads.includes('name="terms_accepted"'));
  assert.match(ads,/Pagamento simulato/i);
  assert.match(ads,/€0 incassati/i);
  assert.ok(migration.includes("'test_no_payment'"));
  assert.ok(index.includes("ugcModerationGate"));
  assert.match(index,/HOLD_FOR_REVIEW|held/i);
  assert.match(privacy,/operatore\/editor definitivo/i);
  assert.match(terms,/non garantisce pubblicazione/i);
  assert.match(editorial,/Ogni immagine deve avere provenienza e diritto d'uso verificabili/i);
});

test("No production payment or automatic UGC publication is introduced",()=>{
  assert.equal(/stripe|paypal|mollie/i.test(ads),false);
  assert.equal(/pubblicat[oa] automaticamente/i.test(ads),false);
});
