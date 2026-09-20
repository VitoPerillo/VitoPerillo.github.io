import test from 'node:test'; import assert from 'node:assert/strict';
import {riskGate,valueGate,factGate} from '../src/core/gates.js'; import {similarity} from '../src/core/dedupe.js';
import {normalizeSourceDate} from '../src/core/pipeline.js';
import {decodeHtmlEntities} from '../src/core/utils.js';
import {parseOfficialArticleHtml,editorialDraftGate} from '../src/core/article.js';

test('P0 RED content never auto-publishes',()=>assert.equal(riskGate({title:'Denuncia contro Mario Rossi',text:'accusa grave'},90).level,'RED'));
test('P0 low local value rejected',()=>assert.equal(valueGate({title:'Breve',text:'testo',area_id:1}).pass,false));
test('P0 local utility accepted',()=>assert.equal(valueGate({title:'Chiusura temporanea di via Vitellia',text:'Lavori e chiusura in via Vitellia domani dalle 09:00 alle 13:00. La modifica interessa il traffico del quartiere e gli accessi locali.',area_id:1}).pass,true));
test('P0 AI changed time blocked',()=>assert.equal(factGate({title:'Chiusura',text:'Via Roma chiusa 09:00-13:00'},{headline:'Chiusura',summary:'',body:'Via Roma chiusa 09:00-18:00'}).pass,false));
test('P0 AI invented address blocked',()=>assert.equal(factGate({title:'Evento',text:'Evento al parco'},{headline:'Evento',summary:'',body:'Evento al parco',address:'Via Inventata 22'}).pass,false));
test('P1 title similarity catches borderline duplicate',()=>assert.ok(similarity('Chiusura via Vitellia per lavori stradali','Lavori stradali: chiude via Vitellia')>=0.5));
test('P0 Italian source date is normalized',()=>assert.equal(normalizeSourceDate('18 settembre 2026'),'2026-09-18T00:00:00.000Z'));
test('P0 standard source date is preserved as ISO',()=>assert.equal(normalizeSourceDate('2026-09-18T14:00:00Z'),'2026-09-18T14:00:00.000Z'));
test('P0 invalid source date does not abort ingestion',()=>assert.equal(normalizeSourceDate('data non disponibile'),null));
test('P1 official HTML entities are decoded',()=>assert.equal(decodeHtmlEntities('&quot;Quartiere&quot; e mobilit&agrave;'), '"Quartiere" e mobilità'));
test('P0 full official page extracts title date and body',()=>{
  const page='<main><div class="Grid-cell u-sizeFull u-md-size3of4 u-lg-size3of4"><h2>Nuovo servizio a Bravetta</h2><p class="List-date">16 settembre 2026</p><p>Roma Capitale ha aperto un nuovo servizio pubblico nel quartiere Bravetta per le famiglie della zona.</p><p>La struttura offre spazi accessibili e resterà aperta nei giorni indicati dal servizio.</p><div data-search-data="x"></div></div></main>';
  const out=parseOfficialArticleHtml(page,'https://www.comune.roma.it/web/it/notizia/esempio.page');
  assert.equal(out.title,'Nuovo servizio a Bravetta');assert.equal(out.date,'16 settembre 2026');assert.equal(out.paragraphs.length,2);
});
test('P0 editorial gate rejects short or copied drafts',()=>{
  const copied='Questa è una frase ufficiale molto lunga '.repeat(8).trim();
  assert.equal(editorialDraftGate(copied,{summary:'Breve',body:'Poco testo'}).pass,false);
  assert.ok(editorialDraftGate(copied,{summary:'Sintesi originale sufficientemente chiara e utile per i residenti del quartiere interessato.',body:copied}).violations.includes('long_source_copy'));
});
