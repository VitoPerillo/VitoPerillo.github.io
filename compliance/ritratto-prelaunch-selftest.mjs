import fs from "node:fs";
const read=p=>fs.readFileSync(new URL("../"+p,import.meta.url),"utf8");
const landing=read("ritratto-stellare/index.html");
const checkout=read("ritratto-public/acquista.html");
const bridge=read("ritratto-public/bridge.js");
const schema=read("ritratto-stellare/cloudflare/schema.sql");
const worker=read("ritratto-stellare/cloudflare/src/index.js");
const plans=JSON.parse(read("ritratto-stellare/plan-matrix.json"));
const privacy=read("ritratto-stellare/privacy/index.html");
const terms=read("ritratto-stellare/terms/index.html");
const withdrawal=read("ritratto-stellare/recesso/index.html");

function need(v,msg){if(!v)throw new Error(msg);}

need(landing.includes("/ritratto-stellare/privacy/"),"standalone privacy link missing");
need(landing.includes("/ritratto-stellare/terms/"),"standalone terms link missing");
need(landing.includes("/ritratto-stellare/recesso/"),"withdrawal link missing");
need(landing.includes("<span>18+</span>"),"18+ disclosure missing");

for(const token of ['name="age_18_plus"','name="terms_accept"','name="immediate_start"','name="legal_version"']) need(checkout.includes(token),"checkout legal field missing: "+token);
need(checkout.includes('orione:{n:"ORIONE"'),"canonical ORIONE checkout missing");
need(!checkout.includes('fenice:{n:"FENICE",m:"€9,90'),"legacy paid FENICE alias detected");
need(bridge.includes('const PLANS=["pegaso","orione","andromeda"]'),"bridge paid plan list not canonical");
need(!bridge.includes('v==="fenice"?"orione"'),"legacy plan remap detected");

need(plans.safety?.live_paypal==="disabled_until_explicit_gate","PayPal LIVE safety flag changed");
need(worker.includes('env.PAYPAL_ENV === "live" && env.LIVE_COMMERCIAL_AUTHORIZED !== "true"'),"runtime live PayPal hard stop missing");

need(schema.includes("CREATE TABLE IF NOT EXISTS legal_acceptances"),"legal acceptance schema missing");
for(const x of ["privacy_version","terms_version","age_18_plus","immediate_start_requested"]) need(schema.includes(x),"legal evidence field missing: "+x);

need(terms.includes("informativa, riflessiva e di intrattenimento"),"astrology boundary missing");
need(terms.includes("non sostituisce consulenza medica"),"high-stakes disclaimer missing");
need(withdrawal.includes("supporto durevole"),"durable-medium confirmation gate missing");
need(privacy.includes("almeno 18 anni"),"privacy age baseline missing");

const combined=[landing,checkout,bridge].join("\n").toLowerCase();
for(const tracker of ["gtag(","google-analytics","googletagmanager","fbq(","facebook.com/tr","clarity(","hotjar","segment.com","mixpanel"]) need(!combined.includes(tracker),"unexpected tracker: "+tracker);

console.log("ritratto-compliance-selftest: PASS");
