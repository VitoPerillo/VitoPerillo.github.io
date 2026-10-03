import {analyzePeriod} from "./rs-astro-engine.js";
import {RS_PLAN_RULES,RS_AREAS} from "./rs-portrait-engine.js";
const LABEL={conjunction:"congiunzione",sextile:"sestile",square:"quadratura",trine:"trigono",opposition:"opposizione"};
const BODY={Sun:"Sole",Moon:"Luna",Mercury:"Mercurio",Venus:"Venere",Mars:"Marte",Jupiter:"Giove",Saturn:"Saturno",Uranus:"Urano",Neptune:"Nettuno",Pluto:"Plutone"};
function importance(a){const slow=["Jupiter","Saturn","Uranus","Neptune","Pluto"].includes(a.transitBody)?0.25:0;const personal=["Sun","Moon","Mercury","Venus","Mars"].includes(a.natalBody)?0.2:0;return a.orb-slow-personal;}
function uniqueEvents(analysis,max){const all=[];for(const s of analysis.samples)for(const a of s.aspects)all.push({...a,date:s.date,score:importance(a)});all.sort((a,b)=>a.score-b.score);const out=[],seen=new Set();for(const e of all){const k=e.natalBody+"|"+e.transitBody+"|"+e.aspect;if(seen.has(k))continue;seen.add(k);out.push(e);if(out.length>=max)break;}return out;}
function eventText(e){return `${BODY[e.transitBody]||e.transitBody} in ${LABEL[e.aspect]||e.aspect} al tuo ${BODY[e.natalBody]||e.natalBody} (orbita ${e.orb.toFixed(1)}°)`;}
function synthesis(events,period,depth,focus){if(!events.length)return `Nel periodo ${period} non emergono aspetti maggiori entro l'orbita selezionata: la lettura resta volutamente sobria, senza inventare segnali.`;const first=eventText(events[0]);const more=events.slice(1).map(eventText);let t=`Il segnale personale più stretto del periodo è ${first}.`;if(depth!=="essential"&&more.length)t+=` Si collega a ${more.slice(0,2).join("; ")}.`;if(depth==="maximum"&&more.length>2)t+=` Nel quadro più ampio entrano anche ${more.slice(2,5).join("; ")}.`;if(focus)t+=` Rispetto al tema che hai indicato (“${String(focus).slice(0,180)}”), questi transiti vengono usati come chiave di riflessione, non come previsione certa.`;return t;}
export function buildPersonalPeriodReading({natalChart,localDate,period,plan="andromeda",profile={},answers={}}){
 const rule=RS_PLAN_RULES[plan];if(!rule)throw new Error("Unsupported plan");
 const step=period==="today"?1:period==="week"?1:period==="month"?2:7;
 const analysis=analyzePeriod({natalChart,localDate,period,stepDays:step,orb:3});
 const cap=rule.depth==="essential"?3:rule.depth==="deep"?6:10;
 const events=uniqueEvents(analysis,cap);
 const areas=RS_AREAS.slice(0,rule.areas);
 return {product:"RITRATTO STELLARE",kind:"personal-period-reading",period,plan:rule.name,profileName:profile.name||null,
   source:{natalUtc:natalChart.utcDate||null,timeKnown:natalChart.timeKnown!==false,timeZone:localDate.timeZone,window:{start:analysis.start,endExclusive:analysis.endExclusive},method:"natal chart + real transits + natal↔transit major aspects"},
   limits:{areas:rule.areas,questionsPerMonth:rule.questions},events:events.map(e=>({date:e.date,natalBody:e.natalBody,transitBody:e.transitBody,aspect:e.aspect,orb:e.orb,text:eventText(e)})),
   summary:synthesis(events,period,rule.depth,answers.focus),areas:areas.map((area,i)=>({area,signal:events.length?eventText(events[i%events.length]):null})),
   integrity:natalChart.timeKnown===false?"Ora sconosciuta: nessun Ascendente, MC o casa viene attribuito come certo.":"Ora nota: gli elementi sensibili all'ora possono essere usati solo se presenti nella carta natale."};
}
