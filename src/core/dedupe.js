import { sha256, normalizeSpace } from './utils.js';
const tokens=s=>new Set(normalizeSpace(s).toLowerCase().replace(/[^\p{L}\p{N}]+/gu,' ').split(' ').filter(x=>x.length>3));
export function similarity(a,b){ const A=tokens(a),B=tokens(b); if(!A.size||!B.size)return 0; let hit=0; for(const x of A)if(B.has(x))hit++; return hit/(A.size+B.size-hit); }
export async function fingerprint(record){
  const day=(record.original_date||record.valid_from||'').slice(0,10);
  const key=[record.area_id||'',day,record.category_id||'',normalizeSpace(record.title).toLowerCase()].join('|');
  return sha256(key);
}
export async function findExisting(db, fp, record){
  let row = await db.prepare('SELECT id,title,updated_at FROM la_content WHERE fingerprint=? LIMIT 1').bind(fp).first();
  if(row) return row;
  const day=(record.original_date||record.valid_from||'').slice(0,10);
  const candidates=await db.prepare("SELECT id,title FROM la_content WHERE area_id=? AND category_id=? AND substr(COALESCE(valid_from,published_at),1,10)=? ORDER BY id DESC LIMIT 30").bind(record.area_id||null,record.category_id||null,day).all();
  for(const c of candidates.results||[]) if(similarity(c.title,record.title)>=0.72) return c;
  return null;
}
