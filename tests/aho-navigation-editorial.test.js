import test from "node:test";
import assert from "node:assert/strict";
import {DatabaseSync} from "node:sqlite";
import {newsPage,contentPage} from "../src/render/pages.js";
import worker from "../src/index.js";
import {enrichOfficialBriefs} from "../src/editorial/hourly-review.js";
import {readFileSync} from "node:fs";

class DB {
  constructor(){
    this.sql=new DatabaseSync(":memory:");
    this.sql.exec([
      "CREATE TABLE la_areas(id INTEGER PRIMARY KEY,name TEXT,slug TEXT,active INTEGER,kind TEXT)",
      "CREATE TABLE la_categories(id INTEGER PRIMARY KEY,name TEXT,slug TEXT,active INTEGER,kind TEXT)",
      "CREATE TABLE la_content(id INTEGER PRIMARY KEY,content_type TEXT,slug TEXT,title TEXT,summary TEXT,body TEXT,status TEXT,area_id INTEGER,category_id INTEGER,published_at TEXT,updated_at TEXT,valid_from TEXT,valid_until TEXT,event_start TEXT,event_end TEXT,venue TEXT,address TEXT,price_type TEXT,image_key TEXT,organizer TEXT,featured INTEGER,source_url TEXT,website TEXT,phone TEXT)",
      "CREATE TABLE la_submissions(id INTEGER PRIMARY KEY AUTOINCREMENT,submission_type TEXT,email TEXT,email_hash TEXT,payload_json TEXT,status TEXT,edit_token_hash TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP)",
      "CREATE TABLE la_rate_limits(key_hash TEXT PRIMARY KEY,action TEXT,count INTEGER,window_start TEXT,expires_at TEXT)",
      "CREATE TABLE la_jobs(id INTEGER PRIMARY KEY AUTOINCREMENT,job_type TEXT,entity_id INTEGER,priority INTEGER,status TEXT,attempts INTEGER,available_at TEXT,payload_json TEXT)"
    ].join(";")+";");
    this.sql.exec("INSERT INTO la_areas VALUES (1,'Roma','roma',1,'city'),(11,'Bravetta','bravetta',1,'neighborhood');INSERT INTO la_categories VALUES (5,'Avvisi utili','avvisi-utili',1,'news')");
  }
  prepare(sql){let params=[];const db=this.sql;
    const api={bind(...v){params=v;return api},
      async first(){return db.prepare(sql).get(...params)??null},
      async all(){return {results:db.prepare(sql).all(...params)}},
      async run(){const x=db.prepare(sql).run(...params);return {meta:{changes:Number(x.changes),last_row_id:Number(x.lastInsertRowid)}}}
    };return api;
  }
  close(){this.sql.close()}
}
function addNews(db,id,day){
  db.sql.prepare("INSERT INTO la_content(id,content_type,slug,title,summary,body,status,area_id,category_id,published_at,updated_at,featured) VALUES(?,'news',?,?,?,?,'published',11,5,?,?,0)")
   .run(id,"notizia-"+id,"Notizia di Bravetta numero "+id,"Avviso di quartiere relativo alla notizia numero "+id,"Il testo completo dell'articolo resta disponibile senza troncamenti.","2026-11-"+String(day).padStart(2,"0")+" 12:00:00","2026-11-21 12:00:00");
}
test("Archivio: frecce e paginazione in alto e in basso",async()=>{
  const db=new DB();try{
    for(let i=1;i<=14;i++)addNews(db,i,Math.min(i,21));
    const one=await (await newsPage(db,"https://ahoroma.test/notizie")).text();
    assert.match(one,/href="\/notizie\?pagina=2"[^>]*>Più vecchie →/);
    assert.match(one,/Pagina 1 di 2/);
    assert.equal((one.match(/Più vecchie →/g)||[]).length,2);
    const two=await (await newsPage(db,"https://ahoroma.test/notizie?pagina=2")).text();
    assert.match(two,/href="\/notizie\?pagina=1"[^>]*>← Più recenti/);
    assert.match(two,/Pagina 2 di 2/);
  }finally{db.close()}
});
test("Articolo: due frecce navigano fra vere notizie, testo integro",async()=>{
 const db=new DB();try{
  addNews(db,1,18);addNews(db,2,19);addNews(db,3,20);
  const h=await (await contentPage(db,"notizia-2","https://ahoroma.test/notizie/notizia-2")).text();
  assert.match(h,/class="news-article-pager"/);
  assert.match(h,/href="\/notizie\/notizia-3"[^>]*>← Più recente/);
  assert.match(h,/href="\/notizie\/notizia-1"[^>]*>Più vecchia →/);
  assert.match(h,/testo completo.*senza troncamenti/);
 }finally{db.close()}
});
async function send(db,ip,description,withPhoto=false){
 const form=new FormData();form.set("submission_type","report");form.set("description",description);form.set("form_started_at",String(Date.now()));
 if(withPhoto)form.set("image",new File(["abc"],"foto.png",{type:"image/png"}));
 return worker.fetch(new Request("https://ahoroma.test/api/submission",{method:"POST",body:form,headers:{"cf-connecting-ip":ip}}),{DB:db},{waitUntil(){}});
}
test("Invio testo rapido senza email/checkbox: 202, HOLD e job revisione",async()=>{
 const db=new DB();try{
  const r=await send(db,"203.0.113.18","Informazione sulla zona Bravetta, la redazione può verificarla dalle fonti.");
  assert.equal(r.status,202);
  assert.equal((await r.json()).status,"held");
  assert.equal(db.sql.prepare("SELECT status FROM la_submissions").get().status,"held");
  assert.equal(db.sql.prepare("SELECT count(*) n FROM la_jobs WHERE job_type='submission_review'").get().n,1);
 }finally{db.close()}
});
test("Foto senza dichiarazione dei diritti: 400 e nessuna pubblicazione",async()=>{
 const db=new DB();try{
  const r=await send(db,"203.0.113.19","Foto originale di un luogo del quartiere Bravetta",true);
  assert.equal(r.status,400);assert.equal((await r.json()).error,"rights_required");
  assert.equal(db.sql.prepare("SELECT count(*) n FROM la_submissions").get().n,0);
 }finally{db.close()}
});
test("Invii ripetuti consentiti fino a 12/h, tredicesimo bloccato",async()=>{
 const db=new DB();try{
  for(let i=0;i<12;i++){
    const r=await send(db,"203.0.113.20","Notizia numero "+i+" da Bravetta, da controllare con attenzione.");
    assert.equal(r.status,202,"report "+i);
  }
  const r=await send(db,"203.0.113.20","Notizia numero 13 da Bravetta, ancora da verificare.");
  assert.equal(r.status,429);
  assert.equal(db.sql.prepare("SELECT count(*) n FROM la_submissions").get().n,12);
 }finally{db.close()}
});
test("Il cron prepara solo due schede ufficiali e non autorizza la pubblicazione",async()=>{
 const c=Array.from({length:3},(_,i)=>({decision:"fetch_full_primary_source_before_review",source_url:"https://www.comune.roma.it/web/it/notizia/example-"+i+".page"}));
 let calls=0;
 await enrichOfficialBriefs(c,async()=>{calls++;return {title:"Roma Capitale informa sui servizi del Municipio XII",date:"2026-11-21",text:"Aggiornamento ufficiale sui servizi di quartiere e sugli avvisi per i cittadini. ".repeat(8),paragraphs:["La comunicazione fornisce aggiornamenti sui servizi nel Municipio XII e nel quartiere Bravetta. ".repeat(4)]}});
 assert.equal(calls,2);
 assert.equal(c[0].evidence_status,"source_retrieved_original_draft_required");
 assert.ok(c[0].source_fact_brief[0].startsWith("La comunicazione"));
 assert.equal(c[2].evidence_status,undefined);
});
test("Interfaccia mobile con Incolla, invio multiplo e consenso foto condizionale",()=>{
 const html=readFileSync(new URL("../public/segnala.html",import.meta.url),"utf8");
 assert.match(html,/id="pasteButton"/);assert.match(html,/id="again"/);
 assert.match(html,/minlength="10" maxlength="20000"/);
 assert.match(html,/policy.hidden=!required/);
 assert.match(html,/id="rightsPolicy" hidden/);
});
