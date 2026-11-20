import test from "node:test";
import assert from "node:assert/strict";
import { DatabaseSync } from "node:sqlite";
import worker from "../src/index.js";
import { sha256 } from "../src/core/utils.js";

const ORIGIN="https://ahoroma.test";
const SOURCE="https://official.example.org/portuense-comunicato";
const TITLE="Il Comune informa sulla chiusura temporanea degli uffici in via Portuense";
const sourceParts=[
  "Il Comune informa che gli uffici in via Portuense sospendono temporaneamente le normali attività per lavori organizzativi.",
  "La comunicazione istituzionale riguarda i servizi degli uffici e le modalità con cui le persone possono ricevere gli aggiornamenti.",
  "Il Comune consiglia di leggere la pagina pubblica della comunicazione prima di recarsi agli uffici interessati dai lavori.",
  "La sede coinvolta si trova in via Portuense e la sospensione del servizio viene descritta come temporanea dalla fonte.",
  "Le informazioni della pagina ufficiale riguardano la sospensione del servizio e il successivo aggiornamento ai cittadini."
];
const OFFICIAL_HTML="<html><body><main><h1>"+TITLE+"</h1>"+sourceParts.map(x=>"<p>"+x+"</p>").join("")+"</main></body></html>";
const SUMMARY="Il Comune comunica la chiusura temporanea degli uffici in via Portuense e invita le persone interessate a consultare la pagina istituzionale per aggiornamenti sul servizio.";
const BODY=[
  "Il Comune informa della chiusura temporanea degli uffici in via Portuense per lavori organizzativi. La comunicazione istituzionale riguarda il servizio e le attività della sede.",
  "Le persone interessate possono consultare la pagina pubblica della comunicazione per conoscere gli aggiornamenti disponibili. Il Comune consiglia di verificare la pagina ufficiale prima di recarsi agli uffici.",
  "Il provvedimento riguarda temporaneamente la sede in via Portuense. La fonte istituzionale spiega la sospensione delle attività e le informazioni destinate ai cittadini.",
  "La comunicazione presenta questa interruzione come temporanea e invita a seguire gli aggiornamenti ufficiali sul servizio. Non vengono aggiunti orari, modalità di accesso o prestazioni non specificate dalla fonte.",
  "Chi deve utilizzare gli uffici può quindi consultare la comunicazione del Comune. Le informazioni sulla sospensione e sulle attività della sede sono disponibili nella fonte ufficiale."
].join("\n\n").repeat(2);
const DRAFT={headline:TITLE,summary:SUMMARY,body:BODY};
const NOTES="Fonte indipendente consultata; avviso originale letto e notizia riscritta senza nuovi fatti.";
const citizen={title:"Gli uffici del quartiere stanno chiudendo",description:"Il servizio degli uffici in via Portuense è temporaneamente sospeso.",area_id:21,category_id:10};

class MemoryD1 {
  constructor(){
    this.sql=new DatabaseSync(":memory:");
    this.sql.exec([
      "CREATE TABLE la_settings(key TEXT PRIMARY KEY,value TEXT,updated_at TEXT DEFAULT CURRENT_TIMESTAMP)",
      "CREATE TABLE la_submissions(id INTEGER PRIMARY KEY,submission_type TEXT,email TEXT,payload_json TEXT,status TEXT,content_id INTEGER,risk_score INTEGER)",
      "CREATE TABLE la_content(id INTEGER PRIMARY KEY AUTOINCREMENT,content_type TEXT,slug TEXT UNIQUE,title TEXT,summary TEXT,body TEXT,area_id INTEGER,category_id INTEGER,fingerprint TEXT,source_url TEXT,status TEXT,published_at TEXT,updated_at TEXT,event_start TEXT,event_end TEXT,venue TEXT,address TEXT,price_type TEXT,external_url TEXT,organizer TEXT,phone TEXT,private_email TEXT,website TEXT,social TEXT,verified_email INTEGER,image_key TEXT,auto_generated INTEGER,risk_level TEXT)",
      "CREATE TABLE la_logs(id INTEGER PRIMARY KEY AUTOINCREMENT,level TEXT,component TEXT,message TEXT,context_json TEXT)"
    ].join(";")+";");
  }
  prepare(sql){
    const engine=this.sql;let params=[];
    const statement=()=>engine.prepare(sql);
    const api={
      bind(...p){params=p;return api},
      async first(){return statement().get(...params)??null},
      async all(){return {results:statement().all(...params)}},
      async run(){const a=statement().run(...params);return {success:true,meta:{changes:Number(a.changes),last_row_id:Number(a.lastInsertRowid)}}}
    };return api;
  }
  async batch(statements){const out=[];for(const s of statements)out.push(await s.run());return out}
  close(){this.sql.close()}
}
async function setup({description=citizen.description,contentId=null}={}){
  const DB=new MemoryD1();const token="a".repeat(64);
  await DB.prepare("CREATE TABLE la_admin_sessions(session_hash TEXT PRIMARY KEY,expires_at TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP,last_seen_at TEXT DEFAULT CURRENT_TIMESTAMP)").run();
  await DB.prepare("INSERT INTO la_admin_sessions(session_hash,expires_at) VALUES(?,?)").bind(await sha256(token),"2099-01-01 00:00:00").run();
  await DB.prepare("INSERT INTO la_submissions(id,submission_type,email,payload_json,status,content_id,risk_score) VALUES(1,'report',?,?,'held',?,10)")
    .bind("synthetic@example.invalid",JSON.stringify({...citizen,description}),contentId).run();
  return {DB,token,env:{DB,ADMIN_EMAIL:"synthetic@example.invalid"},base:DB.sql};
}
async function invoke(env,token,body,{authorized=true}={}){
  const headers={"content-type":"application/json","origin":ORIGIN};
  if(authorized)headers.cookie="la_admin_session="+token;
  const response=await worker.fetch(new Request(ORIGIN+"/api/admin/submissions/review",{
    method:"POST",headers,body:JSON.stringify({id:1,action:"approve",...body})
  }),env,{waitUntil(){}});
  return {status:response.status,json:await response.json()};
}
function countNews(db){return Number(db.prepare("SELECT COUNT(*) n FROM la_content").get().n)}
function status(db){return db.prepare("SELECT status,content_id FROM la_submissions WHERE id=1").get()}
const request={source_url:SOURCE,draft:DRAFT,notes:NOTES};

test("E2E HTTP Worker with isolated synthetic D1, source and authenticated cookie",async t=>{
  const originalFetch=globalThis.fetch;let sourcesRead=0;
  globalThis.fetch=async input=>{
    if(String(input)!==SOURCE)throw Error("Unexpected external request "+String(input));
    sourcesRead++;
    return new Response(OFFICIAL_HTML,{status:200,headers:{"content-type":"text/html; charset=utf-8"}});
  };
  try {
    await t.test("Unauthenticated caller cannot publish",async()=>{
      const {DB,env,token,base}=await setup();
      try{const r=await invoke(env,token,request,{authorized:false});
        assert.equal(r.status,403,JSON.stringify(r));assert.equal(countNews(base),0);assert.equal(status(base).status,"held");
      }finally{DB.close()}
    });
    await t.test("No external source is blocked before publication",async()=>{
      const {DB,env,token,base}=await setup();
      try{const r=await invoke(env,token,{draft:DRAFT,notes:NOTES});
        assert.equal(r.status,422,JSON.stringify(r));assert.equal(r.json.error,"verified_source_required");assert.equal(countNews(base),0);
      }finally{DB.close()}
    });
    await t.test("Invented numeric fact is rejected with held submission",async()=>{
      const {DB,env,token,base}=await setup();
      try{const r=await invoke(env,token,{...request,draft:{...DRAFT,body:DRAFT.body+" Saranno disponibili 19 sportelli."}});
        assert.equal(r.status,422,JSON.stringify(r));assert.equal(r.json.error,"editorial_evidence_failed");assert.equal(countNews(base),0);assert.equal(status(base).status,"held");
      }finally{DB.close()}
    });
    await t.test("Short reviewer notes cannot publish",async()=>{
      const {DB,env,token,base}=await setup();
      try{const r=await invoke(env,token,{...request,notes:"ok"});
        assert.equal(r.status,422,JSON.stringify(r));assert.equal(r.json.error,"review_notes_required");assert.equal(countNews(base),0);
      }finally{DB.close()}
    });
    await t.test("Sensitive allegations remain held",async()=>{
      const {DB,env,token,base}=await setup({description:"Accusa di pedofilia contro una persona."});
      try{const r=await invoke(env,token,request);
        assert.equal(r.status,422,JSON.stringify(r));assert.equal(r.json.error,"sensitive_report_requires_review");assert.equal(countNews(base),0);
      }finally{DB.close()}
    });
    await t.test("Existing source article remains untouched, duplicate blocked",async()=>{
      const {DB,env,token,base}=await setup();
      try{
        await DB.prepare("INSERT INTO la_content(content_type,slug,title,summary,body,area_id,category_id,source_url,status) VALUES('news','originale',?,?,?,?,?,?,'published')")
          .bind(TITLE,"Pre-existing synthetic original","Synthetic content",21,10,SOURCE).run();
        const r=await invoke(env,token,request);
        assert.equal(r.status,409,JSON.stringify(r));assert.equal(r.json.error,"duplicate_story");
        assert.equal(countNews(base),1);assert.equal(status(base).status,"held");
        assert.equal(base.prepare("SELECT title FROM la_content WHERE slug='originale'").get().title,TITLE);
      }finally{DB.close()}
    });
    await t.test("Documented approval creates one original article with audit then prevents replay",async()=>{
      const {DB,env,token,base}=await setup();
      try{
        const r=await invoke(env,token,request);
        assert.equal(r.status,200,JSON.stringify(r));assert.equal(r.json.status,"approved");
        assert.equal(countNews(base),1);
        const article=base.prepare("SELECT content_type,title,summary,body,status,source_url FROM la_content").get();
        assert.equal(article.content_type,"news");assert.equal(article.source_url,SOURCE);
        assert.equal(article.status,"published");assert.equal(article.title,TITLE);assert.equal(article.body,DRAFT.body);
        assert.notEqual(article.body,citizen.description);assert.equal(status(base).status,"approved");
        const audit=base.prepare("SELECT action,reason_code FROM la_moderation_actions WHERE entity_type='submission' ORDER BY id DESC LIMIT 1").get();
        assert.equal(audit.action,"approved");assert.equal(audit.reason_code,"editor_approved");
        const replay=await invoke(env,token,request);assert.equal(replay.status,409);assert.equal(countNews(base),1);
      }finally{DB.close()}
    });
    assert.ok(sourcesRead>0);
  }finally{globalThis.fetch=originalFetch}
});
