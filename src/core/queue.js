import { randomToken, nowIso, safeJson } from './utils.js';

export async function enqueue(db,jobType,entityId=null,payload={},priority=100,availableAt=nowIso()){
  return db.prepare('INSERT INTO la_jobs(job_type,entity_id,priority,status,attempts,available_at,payload_json) VALUES(?,?,?,?,?,?,?)').bind(jobType,entityId,priority,'pending',0,availableAt,JSON.stringify(payload)).run();
}
export async function recoverStale(db){
  return db.prepare("UPDATE la_jobs SET status='retry', lock_token=NULL, locked_at=NULL, available_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP WHERE status='processing' AND locked_at < datetime('now','-10 minutes')").run();
}
export async function claimOne(db){
  const token=randomToken(16);
  const pick=await db.prepare("SELECT id FROM la_jobs WHERE status IN ('pending','retry') AND datetime(available_at)<=CURRENT_TIMESTAMP ORDER BY priority ASC,id ASC LIMIT 1").first();
  if(!pick) return null;
  const res=await db.prepare("UPDATE la_jobs SET status='processing',locked_at=CURRENT_TIMESTAMP,lock_token=?,attempts=attempts+1,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status IN ('pending','retry') AND datetime(available_at)<=CURRENT_TIMESTAMP").bind(token,pick.id).run();
  if((res.meta?.changes||0)!==1) return null;
  const row=await db.prepare('SELECT * FROM la_jobs WHERE id=? AND lock_token=?').bind(pick.id,token).first();
  return row ? {...row,payload:safeJson(row.payload_json,{})} : null;
}
export async function complete(db,job){ await db.prepare("UPDATE la_jobs SET status='completed',lock_token=NULL,locked_at=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=? AND lock_token=?").bind(job.id,job.lock_token).run(); }
export async function retryOrFail(db,job,error){
  const delays=[300,900,3600]; const attempt=Number(job.attempts||1);
  if(attempt>=4){ await db.prepare("UPDATE la_jobs SET status='failed',last_error=?,lock_token=NULL,locked_at=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=? AND lock_token=?").bind(String(error).slice(0,1000),job.id,job.lock_token).run(); return; }
  const sec=delays[Math.min(attempt-1,delays.length-1)]; const avail=new Date(Date.now()+sec*1000).toISOString();
  await db.prepare("UPDATE la_jobs SET status='retry',available_at=?,last_error=?,lock_token=NULL,locked_at=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=? AND lock_token=?").bind(avail,String(error).slice(0,1000),job.id,job.lock_token).run();
}
