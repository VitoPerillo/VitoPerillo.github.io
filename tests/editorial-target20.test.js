import test from "node:test";
import assert from "node:assert/strict";
import {needsFullPrimarySource,hourlyEditorialReview} from "../src/editorial/hourly-review.js";

const now = new Date("2026-11-20T18:00:00Z");
const lead={id:901,original_title:"Municipio XII, iniziativa pubblica nel quartiere Monteverde",
  original_text:"Evento pubblico.",original_date:"2026-11-20T12:00:00Z",
  source_url:"https://www.comune.roma.it/web/it/notizia/iniziativa.page",
  detected_area:3,source_type:"official",trust_level:95,source_name:"Municipio XII",content_id:null,already_published:0};
test("safe official short excerpts receive verification priority, never publication",()=>{
 assert.equal(needsFullPrimarySource(lead,now),true);
 assert.equal(needsFullPrimarySource({...lead,source_type:"community"},now),false);
 assert.equal(needsFullPrimarySource({...lead,already_published:1},now),false);
 assert.equal(needsFullPrimarySource({...lead,original_date:"2026-11-12T12:00:00Z"},now),false);
 assert.equal(needsFullPrimarySource({...lead,source_url:"https://example.com/feed"},now),false);
 assert.equal(needsFullPrimarySource({...lead,original_title:"Accusa di pedofilia a Monteverde"},now),false);
});
test("goal 20 and primary-source referrals are recorded without modifying content",async()=>{
 let rows=Array.from({length:24},(_,i)=>({...lead,id:901+i,source_url:"https://www.comune.roma.it/web/it/notizia/avviso-"+i+".page"}));
 rows.push({...lead,id:1999,already_published:1});
 const writes=[],logs=[];
 const db={prepare(sql){
  const stmt={args:[],bind(...args){this.args=args;return this;},
   async run(){writes.push(sql);if(sql.includes("aho_editorial_hour_v1"))return {meta:{changes:1}};logs.push(JSON.parse(this.args[0]));return {meta:{changes:1}}},
   async all(){return {results:rows}},async first(){return {n:13}}};return stmt;
 }};
 const out=await hourlyEditorialReview(db,now);
 assert.equal(out.target_daily,20);
 assert.equal(out.remaining_to_target,7);
 assert.equal(out.candidates.length,20);
 assert.equal(out.primary_source_expansions,20);
 assert.equal(out.candidates[0].decision,"fetch_full_primary_source_before_review");
 assert.ok(out.candidates.every(x=>x.ingest_id!==1999));
 assert.equal(out.status,"review_only_no_unsupervised_publication");
 assert.equal(writes.length,2);
 assert.ok(writes.every(x=>x.includes("la_settings")));
});
