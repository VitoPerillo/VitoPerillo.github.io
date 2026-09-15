import { stripTags, safeJson } from '../core/utils.js';
import { safeSourceUrl } from '../security/security.js';

const abs=(href,base)=>{ try{return new URL(href,base).toString();}catch{return href;} };
export async function fetchSource(source){
  let current=String(source.url); let r=null;
  for(let hop=0;hop<4;hop++){
    if(!safeSourceUrl(current)) throw new Error('source_url_blocked');
    r=await fetch(current,{headers:{'user-agent':'LOCAL-AUTOPILOT/1.0'},redirect:'manual',signal:AbortSignal.timeout(8000)});
    if([301,302,303,307,308].includes(r.status)){
      const loc=r.headers.get('location'); if(!loc) throw new Error('source_redirect_without_location');
      current=new URL(loc,current).toString(); continue;
    }
    break;
  }
  if(!r || [301,302,303,307,308].includes(r.status)) throw new Error('source_redirect_limit');
  if(!r.ok) throw new Error(`source_http_${r.status}`);
  const text=await r.text();
  if(source.parser_type==='json') return parseJson(text,source);
  if(source.parser_type==='rss') return parseRss(text,source);
  if(source.parser_type==='html') return parseHtml(text,source);
  throw new Error('unsupported_parser');
}
export function parseJson(text,source){
  const cfg=safeJson(source.config_json,{}); const data=JSON.parse(text); const items=cfg.path?cfg.path.split('.').reduce((x,k)=>x?.[k],data):data; if(!Array.isArray(items)) throw new Error('json_items_not_array');
  return items.slice(0,50).map((x,i)=>({external_id:String(x[cfg.id||'id']??'' )||null,source_url:abs(String(x[cfg.url||'url']||source.url),source.url),title:String(x[cfg.title||'title']||''),text:String(x[cfg.text||'text']||x[cfg.description||'description']||''),date:x[cfg.date||'date']||null,raw:x})).filter(x=>x.title&&x.text);
}
export function parseRss(text,source){
  const blocks=[...text.matchAll(/<(?:item|entry)\b[\s\S]*?<\/(?:item|entry)>/gi)].map(m=>m[0]);
  const tag=(b,n)=>{const m=b.match(new RegExp(`<${n}\\b[^>]*>(?:<!\\[CDATA\\[)?([\\s\\S]*?)(?:\\]\\]>)?<\\/${n}>`,'i'));return m?stripTags(m[1].replace(/&lt;/g,'<').replace(/&gt;/g,'>').replace(/&amp;/g,'&')):''};
  return blocks.slice(0,50).map(b=>({external_id:tag(b,'guid')||tag(b,'id')||null,source_url:abs(tag(b,'link')||source.url,source.url),title:tag(b,'title'),text:tag(b,'description')||tag(b,'summary')||tag(b,'content'),date:tag(b,'pubDate')||tag(b,'updated')||null,raw:null})).filter(x=>x.title&&x.text);
}
export function parseHtml(text,source){
  const cfg=safeJson(source.config_json,{}); if(!cfg.item_regex||!cfg.title_regex||!cfg.body_regex) throw new Error('html_config_requires_regex');
  const itemRe=new RegExp(cfg.item_regex,'gis'), titleRe=new RegExp(cfg.title_regex,'is'), bodyRe=new RegExp(cfg.body_regex,'is'), linkRe=cfg.link_regex?new RegExp(cfg.link_regex,'is'):null, dateRe=cfg.date_regex?new RegExp(cfg.date_regex,'is'):null;
  const out=[]; for(const m of text.matchAll(itemRe)){ const b=m[0],t=b.match(titleRe),d=b.match(bodyRe); if(!t||!d)continue; const l=linkRe?b.match(linkRe):null,dt=dateRe?b.match(dateRe):null; out.push({external_id:null,source_url:abs(l?.[1]||source.url,source.url),title:stripTags(t[1]||t[0]),text:stripTags(d[1]||d[0]),date:dt?.[1]||null,raw:null}); if(out.length>=50)break; }
  return out;
}
