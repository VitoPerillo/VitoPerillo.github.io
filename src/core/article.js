import { decodeHtmlEntities, normalizeSpace, stripTags } from './utils.js';
import { safeSourceUrl } from '../security/security.js';

const REDIRECTS=new Set([301,302,303,307,308]);
const absolute=(href,base)=>{try{return new URL(href,base).toString();}catch{return null;}};

async function fetchOfficialHtml(url){
  let current=String(url); let response=null;
  for(let hop=0;hop<4;hop++){
    if(!safeSourceUrl(current))throw new Error('article_url_blocked');
    response=await fetch(current,{headers:{'user-agent':'LOCAL-AUTOPILOT/1.0'},redirect:'manual'});
    if(REDIRECTS.has(response.status)){
      const location=response.headers.get('location');
      if(!location)throw new Error('article_redirect_without_location');
      current=absolute(location,current); continue;
    }
    break;
  }
  if(!response||REDIRECTS.has(response.status))throw new Error('article_redirect_limit');
  if(!response.ok)throw new Error(`article_http_${response.status}`);
  const type=response.headers.get('content-type')||'';
  if(!type.includes('text/html'))throw new Error('article_not_html');
  const html=await response.text();
  if(html.length>1500000)throw new Error('article_too_large');
  return {html,url:current};
}

const meta=(html,property)=>{
  const escaped=property.replace(/[.*+?^${}()|[\]\\]/g,'\\$&');
  const a=html.match(new RegExp(`<meta[^>]+(?:property|name)=["']${escaped}["'][^>]+content=["']([^"']*)["'][^>]*>`,'i'));
  const b=html.match(new RegExp(`<meta[^>]+content=["']([^"']*)["'][^>]+(?:property|name)=["']${escaped}["'][^>]*>`,'i'));
  return normalizeSpace(decodeHtmlEntities(a?.[1]||b?.[1]||''));
};

function paragraphText(fragment){
  return [...fragment.matchAll(/<p\b[^>]*>([\s\S]*?)<\/p>/gi)]
    .map(m=>stripTags(m[1]))
    .map(normalizeSpace)
    .filter(x=>x&&x!=='RED'&&x.length>=25);
}

export function parseOfficialArticleHtml(html,url='https://www.comune.roma.it/'){
  const host=new URL(url).hostname.toLowerCase();
  let fragment='';
  if(host==='comune.roma.it'||host.endsWith('.comune.roma.it')){
    const start=html.search(/<div class=["'][^"']*Grid-cell[^"']*u-md-size3of4[^"']*["'][^>]*>/i);
    const rest=start>=0?html.slice(start):'';
    const end=rest.search(/<div\s+data-search-data=/i);
    fragment=end>=0?rest.slice(0,end):rest;
  }
  if(!fragment){
    fragment=html.match(/<article\b[^>]*>([\s\S]*?)<\/article>/i)?.[1]
      ||html.match(/<main\b[^>]*>([\s\S]*?)<\/main>/i)?.[1]
      ||'';
  }
  if(!fragment)throw new Error('article_body_not_found');
  const title=normalizeSpace(stripTags(fragment.match(/<h[12]\b[^>]*>([\s\S]*?)<\/h[12]>/i)?.[1]||''))
    ||meta(html,'og:title').replace(/^Roma Capitale\s*\|\s*/i,'');
  const localDate=normalizeSpace(stripTags(fragment.match(/<p\b[^>]*class=["'][^"']*List-date[^"']*["'][^>]*>([\s\S]*?)<\/p>/i)?.[1]||''));
  const paragraphs=paragraphText(fragment).filter(x=>x!==title&&!/^Tematica:/i.test(x));
  if(!title||!paragraphs.length)throw new Error('article_content_incomplete');
  return {
    title,
    date:localDate||meta(html,'article:published_time')||null,
    description:meta(html,'og:description')||paragraphs[0],
    paragraphs:paragraphs.slice(0,30),
    text:paragraphs.slice(0,30).join('\n\n'),
    source_url:url
  };
}

export async function fetchArticleDetails(url){
  const page=await fetchOfficialHtml(url);
  return parseOfficialArticleHtml(page.html,page.url);
}

export function reviewFactSheet(detail){
  return detail.paragraphs
    .filter(p=>!/[“”"]/.test(p.slice(0,2)))
    .slice(0,8)
    .map((text,index)=>({id:index+1,text:text.slice(0,520)}));
}

export function editorialDraftGate(sourceText,draft){
  const body=normalizeSpace(draft?.body||''), summary=normalizeSpace(draft?.summary||'');
  const words=body.split(/\s+/).filter(Boolean).length;
  const violations=[];
  if(summary.length<70||summary.length>320)violations.push('summary_length');
  if(words<140||words>700)violations.push('body_length');
  const sourceParagraphs=String(sourceText||'').split(/\n{2,}/).map(normalizeSpace).filter(x=>x.length>=180);
  if(sourceParagraphs.some(p=>body.toLocaleLowerCase('it-IT').includes(p.toLocaleLowerCase('it-IT'))))violations.push('long_source_copy');
  return {pass:violations.length===0,violations,words};
}
