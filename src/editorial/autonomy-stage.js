// Only an explicitly authorized source and deployment can enter the existing reviewed publisher.
export function ahoAutonomyPermission(env,source){
  if(env?.AHO_AUTONOMY_GO!=="legal-applicative-v1")return false;
  const expected=env.AHO_EDITORIAL_QUEUE==="preview"?"3ecc563f-a959-45e6-8f37-d3d1e9c7e44c":env.AHO_EDITORIAL_QUEUE==="live"?"1c083f00-443c-4f86-9dae-fca94c9b616b":null;
  if(!expected||env.AHO_EDITORIAL_DATABASE!==expected)return false;
  if(!source||source.source_type!=="official"||source.usage_policy!=="discovery"||Number(source.active)!==1||Number(source.trust_level)<90)return false;
  let cfg;try{cfg=JSON.parse(source.config_json||"{}")}catch{return false}
  return cfg?.autonomy_rights_verified===true&&typeof cfg.autonomy_rights_ref==="string"&&cfg.autonomy_rights_ref.trim().length>=12&&cfg.autonomy_format==="cie_service_notice";
}

export async function ahoStageReviewedAutonomy(env,row,record,detail,proposal,gates,existing,hashFn,normalizeDate,now=Date.now()){
  const hold=reason=>({status:"held",reason});
  if(env?.AHO_AUTONOMY_GO!=="legal-applicative-v1")return hold("go_disabled");
  if(proposal?.reason!=="cie_service_notice"||existing||row?.content_id||!detail?.date||detail.source_url!==row.source_url)return hold("unsupported_or_duplicate_or_missing_full_evidence");
  if(!Number.isFinite(Number(record.geo_confidence))||Number(record.geo_confidence)<=0)return hold("geography_unconfirmed");
  const actualDate=normalizeDate(detail.date);
  const ts=Date.parse(actualDate||"");
  if(!Number.isFinite(ts)||actualDate!==record.original_date||ts>now+3600000||now-ts>72*3600000)return hold("source_date_unverified_or_stale");
  const source=await env.DB.prepare("SELECT id,url,active,source_type,usage_policy,trust_level,config_json FROM la_sources WHERE id=?").bind(row.source_id).first();
  if(!ahoAutonomyPermission(env,source))return hold("rights_or_environment_denied");
  if(typeof source.url!=="string"||!/^https:\/\/(?:www\.)?comune\.roma\.it\//i.test(source.url))return hold("registered_source_mismatch");
  const sourceHash=await hashFn(detail.text),verifiedAt=new Date(now).toISOString();
  const plan=ahoPlanReviewedHandoff({proposal,gates,record,row,detail,context:{go:true,rightsVerified:true,freshPrimary:true,duplicate:false},sourceHash,verifiedAt});
  if(plan.status!=="ready_for_queue")return hold(plan.reason);
  let prior;try{prior=JSON.parse(row.raw_payload||"{}")}catch{return hold("raw_payload_invalid")}
  if(!prior||Array.isArray(prior)||typeof prior!=="object"||prior.editorial_queue_v1)return hold("already_claimed_or_invalid");
  // Preserve evidence metadata and existing raw payload. The reviewed queue expects
  // SHA256(full source text), not the discovery title/text/URL hash.
  const marker=JSON.stringify({...prior,editorial_queue_v1:true,autonomy_v1:{origin:"aho-deterministic-v1",previous_hash:row.content_hash,previous_title:row.original_title,previous_date:row.original_date,previous_text:row.original_text,verified_at:verifiedAt}});
  if(marker.length>240000)return hold("evidence_backup_too_large");
  const jobType="editorial_reviewed_publish",jobPayload=JSON.stringify(plan.payload);
  const result=await env.DB.batch([
    env.DB.prepare("UPDATE la_ingest SET original_title=?,original_text=?,original_date=?,content_hash=?,raw_payload=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND source_id=? AND status='held' AND content_id IS NULL AND content_hash=? AND COALESCE(raw_payload,'')=? AND NOT EXISTS (SELECT 1 FROM la_jobs WHERE job_type=? AND entity_id=?) AND EXISTS (SELECT 1 FROM la_sources WHERE id=? AND active=1 AND usage_policy='discovery' AND config_json=?)").bind(record.title,detail.text,record.original_date,sourceHash,marker,row.id,row.source_id,row.content_hash,row.raw_payload||"",jobType,row.id,row.source_id,source.config_json),
    env.DB.prepare("INSERT INTO la_jobs(job_type,entity_id,priority,status,attempts,available_at,payload_json) SELECT ?,?,90,'pending',0,CURRENT_TIMESTAMP,? WHERE EXISTS (SELECT 1 FROM la_ingest WHERE id=? AND source_id=? AND status='held' AND content_id IS NULL AND content_hash=? AND raw_payload=?) AND NOT EXISTS (SELECT 1 FROM la_jobs WHERE job_type=? AND entity_id=?)").bind(jobType,row.id,jobPayload,row.id,row.source_id,sourceHash,marker,jobType,row.id)
  ]);
  const updated=Number(result?.[0]?.meta?.changes||0),queued=Number(result?.[1]?.meta?.changes||0);
  return queued===1?{status:"queued",reason:"reviewed_publisher",updated,queued}:hold("already_queued_or_cas_not_matched");
}
