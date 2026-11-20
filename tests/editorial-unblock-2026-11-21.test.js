import test from "node:test";
import assert from "node:assert/strict";
import {DatabaseSync} from "node:sqlite";
import {reviewedStoryNov21,validateVerifiedLocalStory,publishVerifiedLocalStory} from "../src/editorial/verified-local-story-2026-11-21.js";
import worker from "../src/index.js";
import {hmacToken,sha256} from "../src/core/utils.js";

class D1 {
 constructor(){this.db=new DatabaseSync(":memory:");}
 prepare(query){let params=[];const db=this.db;const x={
   bind(...a){params=a;return x},async first(){return db.prepare(query).get(...params)||null},
   async all(){return {results:db.prepare(query).all(...params)}},
   async run(){const a=db.prepare(query).run(...params);return {meta:{changes:Number(a.changes),last_row_id:Number(a.lastInsertRowid)}}}
 };return x}
 close(){this.db.close()}
}
function newsDB(){const d=new D1();d.db.exec("CREATE TABLE la_content(id INTEGER PRIMARY KEY AUTOINCREMENT,content_type TEXT,slug TEXT UNIQUE,title TEXT,summary TEXT,body TEXT,area_id INTEGER,category_id INTEGER,source_url TEXT,status TEXT,published_at TEXT,updated_at TEXT,valid_from TEXT,confidence INTEGER,risk_level TEXT,original_hash TEXT,auto_generated INTEGER)");return d;}
test("Reviewed story passes risk, local utility, factual and originality gates",()=>{
 const g=validateVerifiedLocalStory();
 assert.equal(g.pass,true,JSON.stringify(g.reasons));
 assert.ok(g.words>=140&&g.words<=700);
});
test("Factual invention fails and news never appears in database",async()=>{
 const d=newsDB();try{
  const story=structuredClone(reviewedStoryNov21);
  story.editorDraft.body+=" Le repliche iniziano alle 19:45.";
  assert.equal(validateVerifiedLocalStory(story).pass,false);
  const r=await publishVerifiedLocalStory(d,new Date("2026-11-21T13:00:00Z"),story);
  assert.equal(r.status,"held");
  assert.equal(d.db.prepare("SELECT count(*) n FROM la_content").get().n,0);
 }finally{d.close()}
});
test("A vetted event appears once with source and original editorial copy",async()=>{
 const d=newsDB();try{
  const now=new Date("2026-11-21T13:00:00Z");
  const first=await publishVerifiedLocalStory(d,now);
  assert.equal(first.status,"published",JSON.stringify(first));
  const repeat=await publishVerifiedLocalStory(d,now);
  assert.equal(repeat.status,"duplicate");
  const row=d.db.prepare("SELECT source_url,title,body,auto_generated,area_id FROM la_content").get();
  assert.equal(row.source_url,reviewedStoryNov21.source_url);
  assert.equal(row.auto_generated,0);
  assert.equal(row.area_id,12);
  assert.equal(row.body,reviewedStoryNov21.editorDraft.body);
  assert.equal(d.db.prepare("SELECT count(*) n FROM la_content").get().n,1);
 }finally{d.close()}
});
test("Expired event cannot be injected as breaking news",async()=>{
 const d=newsDB();try{
  const r=await publishVerifiedLocalStory(d,new Date("2026-11-24T09:00:00Z"));
  assert.equal(r.status,"expired_without_publishing");
  assert.equal(d.db.prepare("SELECT count(*) n FROM la_content").get().n,0);
 }finally{d.close()}
});
test("Legacy verify link succeeds once and is idempotent on second click",async()=>{
 const DB=new D1();const tokenSecret="synthetic-test-secret",id=11,expires="2099-01-01 00:00:00";
 DB.db.exec("CREATE TABLE la_submissions(id INTEGER PRIMARY KEY,status TEXT,expires_at TEXT,verification_token_hash TEXT,verified_at TEXT); CREATE TABLE la_jobs(id INTEGER PRIMARY KEY,job_type TEXT,entity_id INTEGER,priority INTEGER,status TEXT,attempts INTEGER,available_at TEXT,payload_json TEXT,locked_at TEXT,lock_token TEXT,last_error TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP,updated_at TEXT DEFAULT CURRENT_TIMESTAMP);");
 const token=await hmacToken(tokenSecret,`verify:${id}:${expires}`);
 await DB.prepare("INSERT INTO la_submissions(id,status,expires_at,verification_token_hash) VALUES(?,?,?,?)").bind(id,"pending_email",expires,await sha256(token)).run();
 const env={DB,TOKEN_SECRET:tokenSecret};
 try{
  const url="https://ahoroma.test/api/verify?id="+id+"&token="+encodeURIComponent(token);
  const request=()=>worker.fetch(new Request(url),env,{waitUntil(){}});
  const first=await request();assert.equal(first.status,200);assert.match(await first.text(),/Email confermata/);
  const second=await request();assert.equal(second.status,200);assert.match(await second.text(),/già stata verificata/);
  assert.equal(DB.db.prepare("SELECT count(*) n FROM la_jobs WHERE job_type='submission_review'").get().n,1);
 }finally{DB.close()}
});
test("Forged legacy link is refused with a helpful page, not JSON error",async()=>{
 const DB=new D1();DB.db.exec("CREATE TABLE la_submissions(id INTEGER PRIMARY KEY,status TEXT,expires_at TEXT,verification_token_hash TEXT,verified_at TEXT)");
 await DB.prepare("INSERT INTO la_submissions(id,status,expires_at) VALUES(1,'held','2026-11-01 00:00:00')").run();
 try{
  const r=await worker.fetch(new Request("https://ahoroma.test/api/verify?id=1&token=fake"),{DB,TOKEN_SECRET:"synthetic-test-secret"},{waitUntil(){}});
  assert.equal(r.status,410);assert.match(await r.text(),/Invia una nuova segnalazione/);
 }finally{DB.close()}
});
