import { normalizeSpace, safeJson } from './utils.js';
export async function classifyGeo(db,record,sourceAreaId=null){
  if(record.area_id) return {area_id:Number(record.area_id),confidence:100,reason:'explicit'};
  const q=await db.prepare("SELECT id,name,cap_json FROM la_areas WHERE active=1 AND kind IN ('municipio','neighborhood')").all();
  const text=` ${normalizeSpace(record.title+' '+record.text).toLowerCase()} `; const hits=[];
  for(const a of q.results||[]){
    const name=String(a.name||'').toLowerCase(); const caps=safeJson(a.cap_json,[]); let score=0;
    if(name && text.includes(` ${name} `)) score+=80;
    for(const cap of Array.isArray(caps)?caps:[]) if(new RegExp(`\\b${String(cap).replace(/\D/g,'')}\\b`).test(text)) score+=30;
    if(score) hits.push({area_id:Number(a.id),confidence:Math.min(100,score),reason:'text_match'});
  }
  hits.sort((a,b)=>b.confidence-a.confidence);
  if(hits.length===1 || (hits[0] && hits[0].confidence>=(hits[1]?.confidence||0)+30)) return hits[0];
  if(sourceAreaId) return {area_id:Number(sourceAreaId),confidence:70,reason:'source_default'};
  return {area_id:null,confidence:hits[0]?.confidence||0,reason:hits.length?'ambiguous':'unknown'};
}
