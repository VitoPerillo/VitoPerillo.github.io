export const nowIso = () => new Date().toISOString().replace(/\.\d{3}Z$/, 'Z');
export const sha256 = async (value) => {
  const data = new TextEncoder().encode(String(value));
  const hash = await crypto.subtle.digest('SHA-256', data);
  return [...new Uint8Array(hash)].map(b => b.toString(16).padStart(2,'0')).join('');
};
export const randomToken = (bytes=32) => {
  const a = crypto.getRandomValues(new Uint8Array(bytes));
  return [...a].map(b=>b.toString(16).padStart(2,'0')).join('');
};
export const safeJson = (s, fallback={}) => { try { return JSON.parse(s || ''); } catch { return fallback; } };
export const clamp = (n,min,max)=>Math.max(min,Math.min(max,n));
export const slugify = (s) => String(s||'').normalize('NFKD').replace(/[\u0300-\u036f]/g,'').toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'').slice(0,90) || 'contenuto';
export const html = (s='') => String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
export const normalizeSpace = s => String(s||'').replace(/\s+/g,' ').trim();
export const stripTags = s => normalizeSpace(String(s||'').replace(/<[^>]*>/g,' '));
export const significantNumbers = s => [...String(s||'').matchAll(/\b\d+(?:[.,]\d+)?\b/g)].map(m=>m[0].replace(',','.'));
export const datesAndTimes = s => [...String(s||'').matchAll(/\b(?:\d{1,2}[\/-]\d{1,2}(?:[\/-]\d{2,4})?|\d{4}-\d{2}-\d{2}|\d{1,2}[:.]\d{2})\b/g)].map(m=>m[0]);
export const properNames = s => [...String(s||'').matchAll(/\b[A-ZÀ-ÖØ-Ý][\p{L}'’.-]+(?:\s+[A-ZÀ-ÖØ-Ý][\p{L}'’.-]+)+/gu)].map(m=>m[0]);
export const responseJson = (data,status=200,headers={}) => new Response(JSON.stringify(data),{status,headers:{'content-type':'application/json; charset=utf-8','cache-control':'no-store',...headers}});

export async function hmacToken(secret,message){ if(!secret) throw new Error('token_secret_missing'); const key=await crypto.subtle.importKey('raw',new TextEncoder().encode(secret),{name:'HMAC',hash:'SHA-256'},false,['sign']); const sig=new Uint8Array(await crypto.subtle.sign('HMAC',key,new TextEncoder().encode(message))); return [...sig].map(b=>b.toString(16).padStart(2,'0')).join(''); }
