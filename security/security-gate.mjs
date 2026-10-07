import fs from "node:fs";
import path from "node:path";

const root=process.cwd();
const roots=[
  "professione-smart",
  "professione-smart-worker",
  "ritratto-stellare",
  "ritratto-public",
  "ritratto-worker",
  "src"
].filter(p=>fs.existsSync(path.join(root,p)));

const exts=new Set([".js",".mjs",".cjs",".ts",".tsx",".jsx",".json",".toml",".yml",".yaml",".html",".md",".sql"]);
const skip=new Set(["node_modules",".git",".wrangler","dist","build",".next"]);

function walk(dir,out=[]){
  for(const entry of fs.readdirSync(dir,{withFileTypes:true})){
    if(skip.has(entry.name)) continue;
    const full=path.join(dir,entry.name);
    if(entry.isDirectory()) walk(full,out);
    else if(exts.has(path.extname(entry.name).toLowerCase())) out.push(full);
  }
  return out;
}

const files=roots.flatMap(r=>walk(path.join(root,r)));
const all=files.map(f=>fs.readFileSync(f,"utf8")).join("\n");

const evidence=[
  ["rate_limit",/rate.?limit|\b429\b/i],
  ["content_security_policy",/content-security-policy/i],
  ["nosniff",/x-content-type-options/i],
  ["referrer_policy",/referrer-policy/i],
  ["permissions_policy",/permissions-policy/i],
  ["csrf_or_same_origin",/csrf|same.?origin|nonce/i],
  ["secure_cookie",/httponly|samesite|__Host-/i],
  ["audit",/audit/i],
  ["signature_or_webhook",/hmac|signature|ed25519|webhook/i]
];

let warnings=0;
for(const [name,re] of evidence){
  if(re.test(all)) console.log("PASS | "+name);
  else { console.log("WARN | "+name); warnings++; }
}

let blocking=0;
for(const file of files){
  const text=fs.readFileSync(file,"utf8");
  if(/\beval\s*\(/.test(text)){
    console.log("HIGH | dynamic_eval | "+path.relative(root,file));
    blocking++;
  }
  if(/new\s+Function\s*\(/.test(text)){
    console.log("HIGH | dynamic_function | "+path.relative(root,file));
    blocking++;
  }
}

console.log("Security Gate: "+(blocking?"BLOCK":"PASS")+" | blocking="+blocking+" | warnings="+warnings);
if(blocking) process.exit(1);
