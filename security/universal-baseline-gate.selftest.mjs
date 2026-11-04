import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import {spawnSync} from "node:child_process";
import {fileURLToPath} from "node:url";

const gate=fileURLToPath(new URL("./universal-baseline-gate.mjs",import.meta.url));
const tmp=fs.mkdtempSync(path.join(os.tmpdir(),"vito-security-v2-"));

function write(rel,text){
  const p=path.join(tmp,rel);
  fs.mkdirSync(path.dirname(p),{recursive:true});
  fs.writeFileSync(p,text);
}

write("README.md","turnstile rate limit authorization nonce cloudflare");
write("artifacts/old/index.js","turnstile rateLimit authorization nonce cloudflare");
write(".stripe-default/extension.js","turnstile rateLimit authorization nonce cloudflare");
write("cloudflare.local-secrets.json","{\"provider\":\"cloudflare\",\"turnstile\":true}");
write("wrangler.toml","name='fixture'");
write("src/index.js",`
export function verifyTurnstile(){ return "cf-turnstile-response"; }
export function rateLimit(){ return new Response("too many",{status:429}); }
export function authorize(req){ return req.headers.get("Authorization"); }
export function rejectReplay(nonce){ if(!nonce) throw new Error("nonce"); }
`);

let r=spawnSync(process.execPath,[gate,tmp],{encoding:"utf8"});
if(r.status!==0) throw new Error("expected PASS\n"+r.stdout+"\n"+r.stderr);
for(const c of ["edge","bot_spam","rate_limit","auth","api_abuse"]){
  if(!r.stdout.includes("PASS|"+c+"|")) throw new Error("missing PASS "+c+"\n"+r.stdout);
}

fs.writeFileSync(path.join(tmp,"src/index.js"),`
export function rateLimit(){ return new Response("too many",{status:429}); }
export function authorize(req){ return req.headers.get("Authorization"); }
export function rejectReplay(nonce){ if(!nonce) throw new Error("nonce"); }
`);
r=spawnSync(process.execPath,[gate,tmp],{encoding:"utf8"});
if(r.status===0) throw new Error("expected BLOCK when bot protection is absent");
if(!r.stdout.includes("FAIL|bot_spam|NO_RUNTIME_EVIDENCE")) throw new Error("false-positive regression\n"+r.stdout);

console.log("SELFTEST=PASS");

[executed on device: VitoPerillo (eb5c5ec8-10c7-462f-bbfa-a8361b6b93d8)]