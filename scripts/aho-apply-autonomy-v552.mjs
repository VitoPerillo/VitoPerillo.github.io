import { readFileSync, writeFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
function ahoApply552Patch(source,scheduler,engine,services,stage){
  const replace=(old,next)=>{const a=source.indexOf(old);if(a<0||source.indexOf(old,a+old.length)!==-1)throw new Error("patch_anchor_mismatch");source=source.slice(0,a)+next+source.slice(a+old.length);};
  const sourceAnchor='async function sourceTick(env) {\n  const q = await env.DB.prepare(\n    "SELECT * FROM la_sources WHERE active=1 AND usage_policy!=\'blocked\' AND (last_checked_at IS NULL OR datetime(last_checked_at, \'+\'||interval_minutes||\' minutes\') <= CURRENT_TIMESTAMP) ORDER BY COALESCE(last_checked_at,\'1970-01-01\') LIMIT 5"\n  ).all();\n  for (const s of q.results || []) {\n    await env.DB.prepare(\n      "UPDATE la_sources SET last_checked_at=CURRENT_TIMESTAMP WHERE id=?"\n    ).bind(s.id).run();';
  if(/\b(?:const|let|var|function)\s+(?:POLL_LIMIT|WINDOW_MS|DUE_AT|FEED|ELIGIBLE|claimSourceWindow|selectDueSources|claimDueSource|ahoBuildEventDraft|ahoBuildServiceDraft|ahoPlanReviewedHandoff|ahoAutonomyPermission|ahoStageReviewedAutonomy)\b/.test(source))throw new Error("scheduler_name_collision");
  replace(sourceAnchor,scheduler.replace(/^export /gm,"")+'\n'+engine.replace(/^export /gm,"")+'\n'+services.replace(/^export /gm,"")+'\n'+stage.replace(/^export /gm,"")+'\nasync function sourceTick(env) {\n  const now = new Date();\n  if (!await claimSourceWindow(env.DB, now)) return;\n  for (const s of await selectDueSources(env.DB, now)) {\n    if (!await claimDueSource(env.DB, s.id, now)) continue;');
  const start='function ahoOfficialAutoBrief(record,detail){';
  const end='\nasync function processIngest(env, id,';
  const p=source.indexOf(start),q=source.indexOf(end,p);
  if(p<0||q<0||source.indexOf(start,p+start.length)!==-1||!source.slice(p,q).includes('const excerpt=text.slice(0,900)'))throw new Error("brief_anchor_mismatch");
  source=source.slice(0,p)+'function ahoOfficialAutoBrief(record,detail){ return ahoBuildEventDraft(record,detail).draft; }\n'+source.slice(q);
  const a='  if(autoOfficial){\n    const generated=ahoOfficialAutoBrief(record,detail);';
  const b='\n  const risk = riskGate(record, Number(row.trust_level));';
  const x=source.indexOf(a),z=source.indexOf(b,x);
  if(x<0||z<0||source.indexOf(a,x+a.length)!==-1||!source.slice(x,z).includes('await publishNews(env.DB,record,generated,existing,fp,row)'))throw new Error("unsafe_publish_anchor_mismatch");
  const safe=`  if(autoOfficial){
    // No implicit approval: keep proposals held. Existing reviewed publisher is unchanged.
    const risk=riskGate(record,Number(row.trust_level));
    const service=risk.level==="GREEN" ? ahoBuildServiceDraft(record,detail) : {draft:null};
    const proposal=service.draft?service:(risk.level==="GREEN"?ahoBuildEventDraft(record,detail):{draft:null});
    let gates=null;
    if(proposal.draft){
      const g=proposal.draft;
      gates={risk,value:valueGate(record),editorial:editorialDraftGate(record.text,g),facts:factGate(record,g),expiry:ahoEventExpiryGate(g.valid_until?"event":"news",g.headline,record.title+" "+record.text,g.valid_until,null)};
    }
    await setStatus(env.DB,id,"held",fp);
    const handoff=await ahoStageReviewedAutonomy(env,row,record,detail,proposal,gates,existing,sha256,normalizeSourceDate);
    // Record a held or queued proposal; never publish directly.
    if(proposal.draft){
      await env.DB.prepare("INSERT INTO la_settings(key,value,updated_at) VALUES(?,?,CURRENT_TIMESTAMP) ON CONFLICT(key) DO UPDATE SET value=excluded.value,updated_at=CURRENT_TIMESTAMP WHERE la_settings.value<>excluded.value").bind("aho_autonomy_draft_v1:"+id,JSON.stringify({ingest_id:id,content_hash:row.content_hash,source_url:row.source_url,status:handoff.status,draft:proposal.draft,gates,handoff})).run();
    }
    return "held";
  }`;
  source=source.slice(0,x)+safe+source.slice(z);
  const publisherAnchor='  if (!row || !safeJson(row.raw_payload, {}).editorial_queue_v1 || row.content_hash !== p.expected_ingest_hash) throw new Error("editorial_ingest_mismatch");';
  const publisherGuard=publisherAnchor+'\n  if(p.origin==="aho-deterministic-v1"||safeJson(row.raw_payload,{}).autonomy_v1){const src=await env.DB.prepare("SELECT active,source_type,usage_policy,trust_level,config_json FROM la_sources WHERE id=?").bind(row.source_id).first();if(p.origin!=="aho-deterministic-v1"||!safeJson(row.raw_payload,{}).autonomy_v1||!ahoAutonomyPermission(env,src))throw new Error("autonomy_revoked_or_unapproved");}';
  replace(publisherAnchor,publisherGuard);
  return source;
}
export { ahoApply552Patch };
const [input,output,expected='71347087f177c0d3aaaa5233c23e10dbf3e00672eecdfcdcb34d3845fc4babf3']=process.argv.slice(2);
if(input&&output){const original=readFileSync(input);if(createHash('sha256').update(original).digest('hex')!==expected)throw new Error('verified_base_hash_required');const scheduler=readFileSync(new URL('../src/sources/source-schedule.js',import.meta.url),'utf8');const engine=readFileSync(new URL('../src/editorial/deterministic-event-draft.js',import.meta.url),'utf8');const services=readFileSync(new URL('../src/editorial/local-service-draft.js',import.meta.url),'utf8');const stage=readFileSync(new URL('../src/editorial/autonomy-stage.js',import.meta.url),'utf8');const candidate=ahoApply552Patch(original.toString('utf8'),scheduler,engine,services,stage);writeFileSync(output,candidate,{flag:'wx'});console.log(JSON.stringify({base:expected,candidate:createHash('sha256').update(candidate).digest('hex')}));}
