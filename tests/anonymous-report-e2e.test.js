import test from "node:test";
import assert from "node:assert/strict";
import { DatabaseSync } from "node:sqlite";
import worker from "../src/index.js";
const origin="https://ahoroma.test";
class D1{
 constructor(){this.sqlite=new DatabaseSync(":memory:");this.sqlite.exec([
 "CREATE TABLE la_rate_limits(key_hash TEXT PRIMARY KEY,action TEXT,count INTEGER,window_start TEXT,expires_at TEXT)",
 "CREATE TABLE la_areas(id INTEGER PRIMARY KEY,slug TEXT,active INTEGER)",
 "CREATE TABLE la_categories(id INTEGER PRIMARY KEY,slug TEXT,kind TEXT,active INTEGER)",
 "CREATE TABLE la_submissions(id INTEGER PRIMARY KEY AUTOINCREMENT,submission_type TEXT NOT NULL,email TEXT NOT NULL,email_hash TEXT NOT NULL,verification_token_hash TEXT,edit_token_hash TEXT,payload_json TEXT NOT NULL,risk_score INTEGER,status TEXT NOT NULL DEFAULT 'pending_email',content_id INTEGER,created_at TEXT DEFAULT CURRENT_TIMESTAMP,verified_at TEXT,expires_at TEXT)",
 "CREATE TABLE la_jobs(id INTEGER PRIMARY KEY AUTOINCREMENT,job_type TEXT,entity_id INTEGER,priority INTEGER,status TEXT,attempts INTEGER,available_at TEXT,payload_json TEXT)"
 ].join(";")+";");
 this.sqlite.prepare("INSERT INTO la_areas(id,slug,active) VALUES(1,'roma',1),(7,'monteverde-nuovo',1)").run();
 this.sqlite.prepare("INSERT INTO la_categories(id,slug,kind,active) VALUES(5,'avvisi-utili','news',1),(8,'eventi','event',1)").run();}
 prepare(sql){let params=[];const db=this.sqlite;const st={bind(...xs){params=xs;return st},async first(){return db.prepare(sql).get(...params)||null},async all(){return {results:db.prepare(sql).all(...params)}},async run(){const r=db.prepare(sql).run(...params);return {meta:{changes:Number(r.changes),last_row_id:Number(r.lastInsertRowid)}}}};return st;}
 close(){this.sqlite.close()}
}
async function send(db,payload){const r=await worker.fetch(new Request(origin+"/api/submission",{method:"POST",headers:{"content-type":"application/json","cf-connecting-ip":"203.0.113.41"},body:JSON.stringify({submission_type:"report",description:"Notizia su Monteverde: aggiornamento del quartiere, informazioni utili da verificare.",rights_declared:"1",form_started_at:Date.now()-5000,...payload})}),{DB:db,TOKEN_SECRET:"synthetic-test-secret"},{waitUntil(){}});return {status:r.status,json:await r.json()}}
test("One textarea, no email/area/category: HTTP 202, anonymous held, exactly one review job",async()=>{
 const db=new D1();try{const r=await send(db,{});assert.equal(r.status,202,JSON.stringify(r));assert.equal(r.json.status,"held");assert.ok(r.json.manage_url.startsWith("/gestisci?id="));
 const row=db.sqlite.prepare("SELECT * FROM la_submissions").get();assert.equal(row.email,"");assert.equal(row.status,"held");assert.equal(row.verification_token_hash,null);assert.ok(row.edit_token_hash);const p=JSON.parse(row.payload_json);assert.equal(p.area_id,1);assert.equal(p.category_id,5);assert.ok(p.title.length>12);
 const jobs=db.sqlite.prepare("SELECT job_type FROM la_jobs").all();assert.deepEqual(jobs.map(x=>x.job_type),["submission_review"]);}finally{db.close()}
});
test("Repeated identical submission is idempotent and no extra jobs",async()=>{const db=new D1();try{const first=await send(db,{});const again=await send(db,{});assert.equal(first.status,202);assert.equal(again.status,202);assert.equal(again.json.duplicate,true);assert.equal(db.sqlite.prepare("SELECT count(*) n FROM la_submissions").get().n,1);assert.equal(db.sqlite.prepare("SELECT count(*) n FROM la_jobs").get().n,1);}finally{db.close()}});
test("Optional email does not require OTP and remains on hold",async()=>{const db=new D1();try{const r=await send(db,{email:"tipster@example.invalid"});assert.equal(r.status,202,JSON.stringify(r));const row=db.sqlite.prepare("SELECT email,status FROM la_submissions").get();assert.equal(row.email,"tipster@example.invalid");assert.equal(row.status,"held");assert.equal(db.sqlite.prepare("SELECT count(*) n FROM la_jobs WHERE job_type='send_verify_email'").get().n,0);}finally{db.close()}});
test("Non-report flows still require email and other metadata; bad source denied",async()=>{const db=new D1();try{let r=await send(db,{submission_type:"event"});assert.equal(r.status,400);assert.equal(r.json.error,"invalid_email");r=await send(db,{source_url:"http://example.com"});assert.equal(r.status,400);assert.equal(r.json.error,"invalid_source_url");assert.equal(db.sqlite.prepare("SELECT count(*) n FROM la_submissions").get().n,0);}finally{db.close()}});
test("Honeypot does not add content or jobs",async()=>{const db=new D1();try{const r=await send(db,{website_hp:"spam"});assert.equal(r.status,202);assert.equal(db.sqlite.prepare("SELECT count(*) n FROM la_submissions").get().n,0);}finally{db.close()}});

test("Real browser multipart/form-data with empty image does not fail",async()=>{
 const db=new D1();try{
  const form=new FormData();
  for(const [key,value] of Object.entries({submission_type:"report",description:"Questo è un testo locale di prova sufficientemente lungo e documentabile.",rights_declared:"1",form_started_at:String(Date.now()-5000),email:"",area_id:"",category_id:""}))form.append(key,value);
  form.append("image",new File([],"",{type:"application/octet-stream"}));
  const request=new Request(origin+"/api/submission",{method:"POST",headers:{"cf-connecting-ip":"203.0.113.77"},body:form});
  const response=await worker.fetch(request,{DB:db,TOKEN_SECRET:"synthetic-test-secret"},{waitUntil(){}});
  const payload=await response.json();
  assert.equal(response.status,202,JSON.stringify(payload));
  assert.equal(payload.status,"held");
  assert.equal(db.sqlite.prepare("SELECT count(*) n FROM la_submissions").get().n,1);
 }finally{db.close()}
});

test("Text-only report sends without rights/photo checkbox",async()=>{
 const db=new D1();try{const r=await send(db,{rights_declared:""});assert.equal(r.status,202,JSON.stringify(r));assert.equal(r.json.status,"held");assert.equal(db.sqlite.prepare("SELECT count(*) n FROM la_submissions").get().n,1);}finally{db.close()}
});
test("Photo and event submissions still require explicit rights declaration",async()=>{
 const db=new D1();try{
  let r=await send(db,{submission_type:"event",rights_declared:"",email:"event@example.invalid",area_id:7,category_id:8,start:"2026-11-30T12:00",venue:"Quartiere"});
  assert.equal(r.status,400);assert.equal(r.json.error,"rights_required");
  const form=new FormData();
  for(const [k,v] of Object.entries({submission_type:"report",description:"Una notizia locale con fotografia da condividere, descrizione sufficientemente lunga.",form_started_at:String(Date.now()-5000)}))form.append(k,v);
  form.append("image",new File([new Uint8Array([137,80,78,71])],"test.png",{type:"image/png"}));
  const resp=await worker.fetch(new Request(origin+"/api/submission",{method:"POST",headers:{"cf-connecting-ip":"203.0.113.88"},body:form}),{DB:db,TOKEN_SECRET:"synthetic-test-secret"},{waitUntil(){}});
  const data=await resp.json();assert.equal(resp.status,400,JSON.stringify(data));assert.equal(data.error,"rights_required");
 }finally{db.close()}
});
