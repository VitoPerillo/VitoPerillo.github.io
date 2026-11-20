import test from "node:test";
import assert from "node:assert/strict";
import {
  classifyHourlyCandidate, editorialHourKey, romeDayStartUtc, hourlyEditorialReview
} from "../src/editorial/hourly-review.js";

const now = new Date("2026-11-20T16:30:00Z");
const article = {
  id: 221, original_title: "Municipio XII: al via lavori di manutenzione nel quartiere Monteverde",
  original_text: "Nel quartiere Monteverde del Municipio XII proseguono i lavori di manutenzione ordinaria della strada. Il Comune informa i cittadini delle modifiche temporanee al servizio e alla viabilità per consentire gli interventi programmati.",
  original_date: "2026-11-20T12:00:00Z", source_url: "https://www.comune.roma.it/web/it/notizia/mun12-lavori.page",
  detected_area: 3, trust_level: 95, source_type: "official", content_id: null, already_published: 0, source_name: "Municipio XII"
};

test("shortlist accepts only recent, useful low-risk official source candidates", () => {
  assert.equal(classifyHourlyCandidate(article, now), "source_candidate_needs_original_draft");
  assert.equal(classifyHourlyCandidate({...article, source_type:"community",trust_level:70},now),
    "secondary_source_needs_primary_verification");
});

test("stale, future and undated source records are not eligible", () => {
  assert.equal(classifyHourlyCandidate({...article, original_date:"2026-11-11T12:00:00Z"},now),
    "stale_or_invalid_date");
  assert.equal(classifyHourlyCandidate({...article, original_date:"2026-11-21T12:00:00Z"},now),
    "stale_or_invalid_date");
  assert.equal(classifyHourlyCandidate({...article, original_date:null},now),
    "stale_or_invalid_date");
});

test("duplicate, unsupported source, unsafe and vague entries fail closed", () => {
  assert.equal(classifyHourlyCandidate({...article, already_published:1},now), "already_published");
  assert.equal(classifyHourlyCandidate({...article, source_url:"https://example.com/lavori"},now),
    "secondary_source_needs_primary_verification");
  assert.equal(classifyHourlyCandidate({...article, original_text:"testo breve"},now),
    "insufficient_local_facts");
  assert.equal(classifyHourlyCandidate({...article, original_text:article.original_text+" Denuncia contro una persona"},now),
    "sensitive_needs_editor");
  assert.equal(classifyHourlyCandidate({...article, original_title:"Notizie culturali", original_text:"Notizie generiche di ieri senza informazioni locali dettagliate e senza indicazioni per i cittadini, riferite a luoghi imprecisati e senza un chiaro beneficio per il territorio."},now),
    "low_local_utility");
});

test("municipio I stays within Trastevere and Porta Portese", () => {
  assert.equal(classifyHourlyCandidate({...article, detected_area:5}, now),"outside_editorial_scope");
  assert.equal(classifyHourlyCandidate({...article, detected_area:5, original_title:"Trastevere: lavori di manutenzione nel quartiere"}, now),
    "source_candidate_needs_original_draft");
});

test("Rome day metrics honor both winter and daylight saving time", () => {
  assert.equal(romeDayStartUtc(new Date("2026-11-20T16:00:00Z")),"2026-11-19 23:00:00");
  assert.equal(romeDayStartUtc(new Date("2026-07-20T12:00:00Z")),"2026-07-19 22:00:00");
  assert.equal(editorialHourKey(now),"2026-11-20T16");
});

test("hourly review is idempotent and cannot publish an article", async () => {
  const dbState = {hour:"",digest:null,writes:[],sourceReads:0};
  const db = {
    prepare(sql) {
      const q = {args:[],bind(...values){this.args=values;return this;},
        async run(){
          dbState.writes.push(sql);
          assert.ok(sql.includes("la_settings"),"hourly code must only write la_settings");
          if(sql.includes("aho_editorial_hour_v1")){
            const fresh=dbState.hour!==this.args[0]; dbState.hour=this.args[0]; return {meta:{changes:fresh?1:0}};
          }
          dbState.digest=JSON.parse(this.args[0]);
          return {meta:{changes:1}};
        },
        async all(){ dbState.sourceReads++;return {results:[article]};},
        async first(){return {n:3};}
      };
      return q;
    }
  };
  const first=await hourlyEditorialReview(db,now);
  assert.equal(first.status,"review_only_no_unsupervised_publication");
  assert.equal(first.published_today_rome,3);
  assert.equal(first.target_daily,20);
  assert.equal(first.remaining_to_target,17);
  assert.equal(first.candidates.length,1);
  assert.equal(dbState.digest.candidates[0].ingest_id,221);
  const repeat=await hourlyEditorialReview(db,now);
  assert.equal(repeat.status,"already_checked");
  assert.equal(dbState.sourceReads,1);
  assert.equal(dbState.writes.length,3); // hour lock, digest, repeated hour no-op
});
