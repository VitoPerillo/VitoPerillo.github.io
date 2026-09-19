import { aiProvider } from '../ai/gemini.js';
import { riskGate,valueGate,factGate } from './gates.js';
import { fingerprint,findExisting } from './dedupe.js';
import { slugify,sha256,nowIso,normalizeSpace,decodeHtmlEntities } from './utils.js';
import { enqueue } from './queue.js';
import { classifyGeo } from './geo.js';

export async function processIngest(env,id,{editorApproved=false}={}){
  const row=await env.DB.prepare('SELECT i.*,s.trust_level,s.usage_policy FROM la_ingest i JOIN la_sources s ON s.id=i.source_id WHERE i.id=?').bind(id).first();
  if(!row) throw new Error('ingest_not_found');
  if(['published','updated','rejected'].includes(row.status)) return row.status;
  if(row.usage_policy==='blocked'){await setStatus(env.DB,id,'rejected'); return 'rejected';}
  if(row.usage_policy==='discovery'&&!editorApproved){await setStatus(env.DB,id,'held'); return 'held';}
  const record={title:normalizeSpace(decodeHtmlEntities(row.original_title)),text:normalizeSpace(decodeHtmlEntities(row.original_text)),source_url:row.source_url,original_date:row.original_date,area_id:row.detected_area||null,category_id:row.detected_category||null};
  const geo=await classifyGeo(env.DB,record,row.detected_area||null); record.area_id=geo.area_id; record.geo_confidence=geo.confidence;
  if(!record.area_id || geo.reason==='ambiguous'){await setStatus(env.DB,id,'held'); return 'held';}
  const fp=await fingerprint(record); const existing=await findExisting(env.DB,fp,record);
  const risk=riskGate(record,Number(row.trust_level));
  if(risk.level==='RED'){await setStatus(env.DB,id,'rejected',fp); return 'rejected';}
  if(risk.level==='YELLOW'&&editorApproved){await setStatus(env.DB,id,'held',fp); return 'held';}
  if(risk.level==='YELLOW'){
    try{const ar=await aiProvider(env).classifyRisk(record); if(ar.level!=='GREEN'){await setStatus(env.DB,id,'held',fp); return 'held';}}
    catch{await setStatus(env.DB,id,'held',fp); return 'held';}
  }
  const value=valueGate(record);
  const approvedMinimum=record.title.length>=12&&record.text.length>=20&&Number(record.area_id||0)>0;
  if(!value.pass&&!(editorApproved&&approvedMinimum)){await setStatus(env.DB,id,'rejected',fp); return 'rejected';}
  let generated;
  if(editorApproved){generated={headline:record.title,summary:record.text.slice(0,220),body:record.text,valid_from:record.original_date||null,valid_until:null,confidence:Number(row.trust_level||50),social_text:record.title};}
  else try{generated=await aiProvider(env).generateArticle(record);}catch(e){throw e;}
  const fact=factGate(record,generated); if(!fact.pass){await setStatus(env.DB,id,'held',fp); await log(env.DB,'warning','fact_gate','Generated facts not grounded',{ingest_id:id,violations:fact.violations}); return 'held';}
  const contentId=await publishNews(env.DB,record,generated,existing,fp,row);
  await env.DB.prepare('UPDATE la_ingest SET status=?,fingerprint=?,content_id=?,updated_at=CURRENT_TIMESTAMP WHERE id=?').bind(existing?'updated':'published',fp,contentId,id).run();
  await enqueue(env.DB,'social_publish',contentId,{social_text:generated.social_text||generated.headline||record.title},200);
  return existing?'updated':'published';
}
async function setStatus(db,id,status,fp=null){await db.prepare('UPDATE la_ingest SET status=?,fingerprint=COALESCE(?,fingerprint),updated_at=CURRENT_TIMESTAMP WHERE id=?').bind(status,fp,id).run();}
async function publishNews(db,r,g,existing,fp,row){
  const title=normalizeSpace(g.headline||r.title),summary=normalizeSpace(g.summary||r.text.slice(0,220)),body=normalizeSpace(g.body||r.text),validFrom=g.valid_from||r.original_date||null,validUntil=g.valid_until||null;
  if(existing){await db.prepare("UPDATE la_content SET title=?,summary=?,body=?,source_url=?,status='updated',updated_at=CURRENT_TIMESTAMP,valid_from=?,valid_until=?,confidence=?,risk_level='GREEN',original_hash=?,auto_generated=1 WHERE id=?").bind(title,summary,body,r.source_url,validFrom,validUntil,Number(g.confidence||50),row.content_hash,existing.id).run(); return existing.id;}
  let slug=slugify(title); const clash=await db.prepare('SELECT 1 FROM la_content WHERE slug=?').bind(slug).first(); if(clash) slug=`${slug}-${String(fp).slice(0,8)}`;
  const res=await db.prepare("INSERT INTO la_content(content_type,slug,title,summary,body,area_id,category_id,source_id,source_url,fingerprint,status,published_at,updated_at,valid_from,valid_until,confidence,risk_level,original_hash,auto_generated) VALUES('news',?,?,?,?,?,?,?,?,?,'published',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,?,?,?,?,?,1)").bind(slug,title,summary,body,r.area_id,r.category_id,row.source_id,r.source_url,fp,validFrom,validUntil,Number(g.confidence||50),'GREEN',row.content_hash).run(); return Number(res.meta.last_row_id);
}
export async function log(db,level,component,message,context={}){const safe={...context}; for(const k of Object.keys(safe)) if(/email|token|key|secret|phone/i.test(k)) delete safe[k]; await db.prepare('INSERT INTO la_logs(level,component,message,context_json) VALUES(?,?,?,?)').bind(level,component,message,JSON.stringify(safe)).run();}
export function normalizeSourceDate(value){
  if(!value)return null;
  const raw=normalizeSpace(String(value));
  const direct=Date.parse(raw);
  if(Number.isFinite(direct))return new Date(direct).toISOString();
  const months={gennaio:1,febbraio:2,marzo:3,aprile:4,maggio:5,giugno:6,luglio:7,agosto:8,settembre:9,ottobre:10,novembre:11,dicembre:12};
  const m=raw.toLocaleLowerCase('it-IT').match(/^(\d{1,2})\s+([a-zà-ÿ]+)\s+(\d{4})$/i);
  if(!m||!months[m[2]])return null;
  const day=Number(m[1]),month=months[m[2]],year=Number(m[3]);
  const date=new Date(Date.UTC(year,month-1,day));
  if(date.getUTCFullYear()!==year||date.getUTCMonth()!==month-1||date.getUTCDate()!==day)return null;
  return date.toISOString();
}
export async function ingestItem(db,source,item){ const contentHash=await sha256(`${item.title}|${item.text}|${item.source_url}`); const ext=item.external_id||null; const duplicate=await db.prepare('SELECT id FROM la_ingest WHERE source_url=? OR (source_id=? AND content_hash=?) LIMIT 1').bind(item.source_url,source.id,contentHash).first(); if(duplicate)return null; try{const res=await db.prepare('INSERT INTO la_ingest(source_id,external_id,source_url,original_title,original_text,original_date,content_hash,status,detected_area,detected_category,raw_payload) VALUES(?,?,?,?,?,?,?,\'new\',?,?,?)').bind(source.id,ext,item.source_url,item.title,item.text,normalizeSourceDate(item.date),contentHash,source.area_id||null,source.category_id||null,item.raw?JSON.stringify(item.raw).slice(0,200000):null).run(); return Number(res.meta.last_row_id);}catch(e){if(String(e).includes('UNIQUE'))return null; throw e;}}
