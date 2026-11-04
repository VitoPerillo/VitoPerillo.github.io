[Reading 154 lines from start (total: 154 lines, 0 remaining)]

#!/usr/bin/env node
import fs from "node:fs";
import path from "node:path";

const args=process.argv.slice(2);
const reportOnly=args.includes("--report-only");
const roots=args.filter(x=>!x.startsWith("--"));
if(!roots.length){
  console.error("usage: node universal-baseline-gate.mjs [--report-only] <project> [...]");
  process.exit(2);
}

const EXCLUDED_DIRS=new Set([
  "node_modules",".git",".wrangler",".next","dist","build","coverage","vendor",
  "artifacts","artifact","backup","backups","archive","archives","release","releases",
  "tmp","temp",".cache","docs","documentation"
]);
const EXCLUDED_DIR_RE=/^(?:\.|tests?|fixtures?|snapshots?|recovery|prechange|old|copy|compliance-test|security-verify|p\d+-)/i;
const EXCLUDED_FILE_RE=/(?:^\.env|\.dev\.vars|local-secrets|credentials?|client-private|private-key)/i;
const SOURCE_EXT=new Set([".js",".mjs",".cjs",".ts",".tsx",".jsx",".py",".php",".toml",".yml",".yaml",".json",".sql"]);
const TEST_MARKERS=[/[/\\]tests?[/\\]/i,/\.test\./i,/\.spec\./i,/[/\\]security[/\\].*gate/i];

const controls=[
  {
    id:"edge",
    label:"EDGE",
    runtime:[/cloudflare/i,/wrangler/i,/vercel/i,/reverse.?proxy/i,/firewall/i,/waf/i],
    strong:[/wrangler\.(toml|jsonc?)$/i,/vercel\.json$/i,/\bwaf\b/i,/firewall/i,/security.?headers/i]
  },
  {
    id:"bot_spam",
    label:"BOT/SPAM",
    runtime:[/turnstile/i,/cf-turnstile-response/i,/honeypot/i,/captcha/i,/bot.?score/i],
    strong:[/turnstile/i,/cf-turnstile-response/i,/honeypot/i]
  },
  {
    id:"rate_limit",
    label:"RATE LIMIT",
    runtime:[/rate.?limit/i,/too many requests/i,/\b429\b/i],
    strong:[/rate.?limit/i,/status\s*[:=]\s*429/i,/Response\([^\n]*429/i]
  },
  {
    id:"auth",
    label:"AUTH",
    runtime:[/authorization/i,/bearer/i,/permission_callback/i,/current_user_can/i,/user_can\(/i,/jwt/i,/oauth/i,/verify.*session/i],
    strong:[/permission_callback/i,/current_user_can/i,/user_can\(/i,/authorization/i,/verify.*(token|session|signature)/i]
  },
  {
    id:"api_abuse",
    label:"API ABUSE",
    runtime:[/nonce/i,/replay/i,/idempot/i,/signature/i,/hmac/i,/ed25519/i,/quota/i,/budget/i],
    strong:[/replay/i,/nonce/i,/idempot/i,/verify.*signature/i,/hmac/i,/quota/i,/budget/i]
  }
];

function walk(root){
  const files=[];
  const stack=[root];
  while(stack.length){
    const dir=stack.pop();
    let entries=[];
    try{entries=fs.readdirSync(dir,{withFileTypes:true});}catch{continue;}
    for(const e of entries){
      const full=path.join(dir,e.name);
      if(e.isDirectory()){
        if(!EXCLUDED_DIRS.has(e.name.toLowerCase()) && !EXCLUDED_DIR_RE.test(e.name)) stack.push(full);
      } else if(e.isFile() && SOURCE_EXT.has(path.extname(e.name).toLowerCase()) && !EXCLUDED_FILE_RE.test(e.name)){
        files.push(full);
      }
    }
  }
  return files;
}

function loadManifest(root){
  const file=path.join(root,".vito-security.json");
  if(!fs.existsSync(file)) return {exceptions:{}};
  try{
    const data=JSON.parse(fs.readFileSync(file,"utf8"));
    return {exceptions:data.exceptions||{}};
  }catch{
    return {exceptions:{},manifestError:true};
  }
}

function lineOf(text,rx){
  const m=rx.exec(text);
  if(!m)return 0;
  return text.slice(0,m.index).split(/\r?\n/).length;
}

function findEvidence(root,files,control){
  const runtime=[], tests=[];
  for(const file of files){
    const rel=path.relative(root,file);
    let text="";
    try{text=fs.readFileSync(file,"utf8");}catch{continue;}
    const isTest=TEST_MARKERS.some(rx=>rx.test(file));
    let matched=null;
    for(const rx0 of control.runtime){
      const rx=new RegExp(rx0.source,rx0.flags.replace("g",""));
      if(rx.test(text)||rx.test(rel)){matched=rx;break;}
    }
    const strong=control.strong.some(rx0=>{
      const rx=new RegExp(rx0.source,rx0.flags.replace("g",""));
      return rx.test(text)||rx.test(rel);
    });
    if(!matched && !strong)continue;
    const ev={file:rel,line:matched?lineOf(text,matched):0,strong};
    (isTest?tests:runtime).push(ev);
  }
  return {runtime,tests};
}

let blocking=0;
for(const root of roots){
  console.log("=== "+root+" ===");
  if(!fs.existsSync(root)){
    console.log("FAIL|project|NOT_FOUND");
    blocking++;
    continue;
  }
  const manifest=loadManifest(root);
  if(manifest.manifestError){
    console.log("FAIL|manifest|INVALID_JSON");
    blocking++;
  }
  const files=walk(root);
  for(const control of controls){
    const exception=manifest.exceptions?.[control.id];
    if(typeof exception==="string" && exception.trim().length>=12){
      console.log("N/A|"+control.id+"|"+exception.trim());
      continue;
    }
    const ev=findEvidence(root,files,control);
    const strongRuntime=ev.runtime.filter(x=>x.strong);
    let state="FAIL";
    let evidence="NO_RUNTIME_EVIDENCE";
    if(strongRuntime.length){
      state="PASS";
      evidence=strongRuntime[0].file+":"+strongRuntime[0].line;
    }else if(ev.runtime.length){
      state="PARTIAL";
      evidence=ev.runtime[0].file+":"+ev.runtime[0].line;
    }else if(ev.tests.length){
      state="PARTIAL";
      evidence="TEST_ONLY:"+ev.tests[0].file+":"+ev.tests[0].line;
    }
    console.log(state+"|"+control.id+"|"+evidence);
    if(state!=="PASS")blocking++;
  }
}
console.log("UNIVERSAL_BASELINE_GATE="+(blocking?"BLOCK":"PASS")+"|blocking="+blocking);
if(!reportOnly && blocking)process.exit(1);

[executed on device: VitoPerillo (eb5c5ec8-10c7-462f-bbfa-a8361b6b93d8)]