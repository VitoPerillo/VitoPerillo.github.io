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


test("Local Autopilot retention and tracking baseline",()=>{
  const index=read("src/index.js");
  const wrangler=read("wrangler.jsonc");
  assert.match(index,/DELETE FROM la_ingest WHERE created_at<datetime\('now','-30 days'\)/);
  assert.match(index,/DELETE FROM la_jobs WHERE status='completed' AND updated_at<datetime\('now','-14 days'\)/);
  assert.match(index,/DELETE FROM la_logs WHERE level='info' AND created_at<datetime\('now','-14 days'\)/);
  assert.match(index,/level IN \('warning','error'\) AND created_at<datetime\('now','-90 days'\)/);
  assert.match(index,/status IN \('approved','rejected'\)/);
  assert.match(index,/private_email=NULL/);
  assert.match(index,/resolved_at<datetime\('now','-180 days'\)/);
  assert.match(index,/approved_test','rejected','cancelled/);
  assert.match(index,/DELETE FROM la_moderation_actions WHERE created_at<datetime\('now','-365 days'\)/);
  assert.match(wrangler,/"EMAIL_PROVIDER": "brevo"/);
  assert.match(wrangler,/"AI_PROVIDER": "gemini"/);
  const publicFiles=["public/privacy.html","public/termini.html","public/regole-editoriali.html"].map(read).join("\n");
  const combined=index+"\n"+publicFiles;
  assert.equal(/googletagmanager|google-analytics|gtag\(|connect\.facebook\.net|fbq\(|meta pixel|hotjar|clarity\.ms/i.test(combined),false);
  assert.equal(/document\.cookie|set-cookie/i.test(combined),false);
});
