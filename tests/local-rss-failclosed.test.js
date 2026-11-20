import test from "node:test";
import assert from "node:assert/strict";
import {parseRss} from "../src/sources/adapters.js";
import {processIngest} from "../src/core/pipeline.js";

const xml = `<?xml version="1.0"?><rss><channel>
<item><title>Monteverde, lavori di manutenzione in piazza</title><link>https://abitarearoma.it/monteverde-lavori/</link><description>Il Municipio XII annuncia interventi alla viabilità di Monteverde questa settimana.</description><pubDate>Fri, 20 Nov 2026 13:00:00 GMT</pubDate></item>
<item><title>Tor Bella Monaca, iniziativa scolastica</title><link>https://abitarearoma.it/tor-bella-monaca/</link><description>Il Municipio VI organizza attività per le famiglie.</description><pubDate>Fri, 20 Nov 2026 14:00:00 GMT</pubDate></item>
</channel></rss>`;

test("RSS terms restrict a Rome-wide feed to relevant neighborhoods",()=>{
  const source={url:"https://abitarearoma.it/feed/",config_json:JSON.stringify({include_terms:["monteverde","bravetta","corviale"]})};
  const matched=parseRss(xml,source);
  assert.equal(matched.length,1);
  assert.match(matched[0].title,/Monteverde/);
  assert.match(matched[0].source_url,/monteverde-lavori/);
});

test("existing RSS feeds without include_terms retain their original behavior",()=>{
  assert.equal(parseRss(xml,{url:"https://example.org/feed",config_json:"{}"}).length,2);
});

test("invalid RSS filter config fails closed rather than importing all articles",()=>{
  const source={url:"https://abitarearoma.it/feed/",config_json:JSON.stringify({include_terms:"Roma"})};
  assert.equal(parseRss(xml,source).length,0);
  assert.equal(parseRss(xml,{...source,config_json:'{"include_terms":[]}'}).length,0);
});

test("automatic publishing is blocked even for low-risk source=auto",async()=>{
  const row={
    id:27,status:"new",source_type:"official",trust_level:95,usage_policy:"auto",
    original_title:"Monteverde, lavori di manutenzione e cambi alla viabilità",
    original_text:"Il Municipio XII comunica che a Monteverde sono previsti interventi di manutenzione della carreggiata e variazioni della viabilità per consentire le operazioni, con aggiornamenti sul servizio rivolti ai residenti.",
    original_date:"2026-11-20T14:00:00Z",source_url:"https://www.comune.roma.it/web/it/notizia/lavori-monteverde.page",
    detected_area:3,detected_category:1,content_hash:"fake",
    content_id:null
  };
  const writes=[], reads=[];
  const db={
    prepare(sql){
      reads.push(sql);
      const stmt={
        bind(){return this;},
        async first(){return sql.includes("FROM la_ingest i JOIN la_sources")?row:null;},
        async all(){return {results:[]};},
        async run(){writes.push(sql);return {meta:{changes:1}};}
      };
      return stmt;
    }
  };
  const result=await processIngest({DB:db,AI_PROVIDER:"null"},27);
  assert.equal(result,"held");
  assert.equal(writes.length,1);
  assert.match(writes[0],/^UPDATE la_ingest SET status=/);
  assert.ok(!writes.some(x=>x.includes("INSERT INTO la_content")));
  assert.ok(!writes.some(x=>x.includes("social_publish")));
});
