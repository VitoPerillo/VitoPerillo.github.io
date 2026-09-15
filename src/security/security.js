import { sha256, nowIso } from '../core/utils.js';
export async function requireAdmin(request,env){
  const got=request.headers.get('authorization')||'';
  if(!env.ADMIN_TOKEN || got!==`Bearer ${env.ADMIN_TOKEN}`) return false;
  return true;
}
export async function rateLimit(db, request, action, limit, windowSeconds){
  const ip=request.headers.get('cf-connecting-ip')||'unknown';
  const key=await sha256(`${ip}|${action}`); const now=Date.now();
  const row=await db.prepare('SELECT count,window_start,expires_at FROM la_rate_limits WHERE key_hash=?').bind(key).first();
  if(!row || Date.parse(row.expires_at)<=now){
    const start=nowIso(), exp=new Date(now+windowSeconds*1000).toISOString();
    await db.prepare('INSERT INTO la_rate_limits(key_hash,action,count,window_start,expires_at) VALUES(?,?,?,?,?) ON CONFLICT(key_hash) DO UPDATE SET action=excluded.action,count=1,window_start=excluded.window_start,expires_at=excluded.expires_at').bind(key,action,1,start,exp).run();
    return true;
  }
  if(row.count>=limit) return false;
  await db.prepare('UPDATE la_rate_limits SET count=count+1 WHERE key_hash=?').bind(key).run(); return true;
}

export function validFormTiming(value, minSeconds=3, maxSeconds=86400){
  const t=Number(value||0); if(!Number.isFinite(t)||t<=0)return false;
  const age=(Date.now()-t)/1000; return age>=minSeconds && age<=maxSeconds;
}

export function validUpload(file){
  if(!file) return true;
  return ['image/jpeg','image/png','image/webp'].includes(file.type) && file.size <= 1000000;
}

export function stripImageMetadata(bytes, mime){
  const src = bytes instanceof Uint8Array ? bytes : new Uint8Array(bytes);
  if(mime==='image/jpeg') return stripJpegAppMetadata(src);
  if(mime==='image/webp') return stripWebpExif(src);
  return src;
}
function stripJpegAppMetadata(src){
  if(src.length<4||src[0]!==0xFF||src[1]!==0xD8) return src;
  const out=[src.slice(0,2)]; let i=2;
  while(i+3<src.length && src[i]===0xFF){
    const marker=src[i+1];
    if(marker===0xDA){out.push(src.slice(i)); return concat(out);}
    if(marker===0xD9){out.push(src.slice(i,i+2)); return concat(out);}
    const len=(src[i+2]<<8)|src[i+3]; if(len<2||i+2+len>src.length) return src;
    const segment=src.slice(i,i+2+len);
    const isMeta=(marker>=0xE1&&marker<=0xEF) || marker===0xFE;
    if(!isMeta) out.push(segment);
    i+=2+len;
  }
  if(i<src.length)out.push(src.slice(i)); return concat(out);
}
function stripWebpExif(src){
  if(src.length<12 || String.fromCharCode(...src.slice(0,4))!=='RIFF' || String.fromCharCode(...src.slice(8,12))!=='WEBP') return src;
  const chunks=[]; let i=12;
  while(i+8<=src.length){const type=String.fromCharCode(...src.slice(i,i+4)); const size=src[i+4]|(src[i+5]<<8)|(src[i+6]<<16)|(src[i+7]<<24); const end=i+8+size+(size%2); if(end>src.length)break; if(type!=='EXIF')chunks.push(src.slice(i,end)); i=end;}
  const body=concat(chunks), out=new Uint8Array(12+body.length); out.set(src.slice(0,12)); out.set(body,12); const size=4+body.length; out[4]=size&255;out[5]=(size>>>8)&255;out[6]=(size>>>16)&255;out[7]=(size>>>24)&255; return out;
}
function concat(parts){const n=parts.reduce((s,p)=>s+p.length,0),o=new Uint8Array(n);let at=0;for(const p of parts){o.set(p,at);at+=p.length;}return o;}
export function safeSourceUrl(value){
  let u; try{u=new URL(value);}catch{return false;} if(!['http:','https:'].includes(u.protocol))return false;
  const h=u.hostname.toLowerCase(); if(h==='localhost'||h.endsWith('.local')||h==='0.0.0.0'||h==='127.0.0.1'||h==='::1'||/^10\./.test(h)||/^192\.168\./.test(h)||/^169\.254\./.test(h)||/^172\.(1[6-9]|2\d|3[01])\./.test(h))return false; return true;
}
