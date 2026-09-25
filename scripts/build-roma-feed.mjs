import fs from "node:fs/promises";

const BASE = "https://www.comune.roma.it";
const OUT = new URL("../public/feeds/roma-capitale.json", import.meta.url);
const LISTS = [
  ["municipio_xi", "municipio-xi", "Municipio XI"],
  ["municipio_xii", "municipio-xii", "Municipio XII"],
  ["municipio_xiii", "municipio-xiii", "Municipio XIII"],
  ["municipio_i", "municipio-i-trastevere", "Municipio I"],
];
const NEIGHBORHOODS = [
  ["monteverde-vecchio","Monteverde Vecchio"],["monteverde-nuovo","Monteverde Nuovo"],
  ["gianicolense","Gianicolense"],["colli-portuensi","Colli Portuensi"],["casaletto","Casaletto"],
  ["bravetta","Bravetta"],["villa-pamphilj","Villa Pamphilj"],["pisana","Pisana"],
  ["massimina","Massimina"],["casal-lumbroso","Casal Lumbroso"],["portuense","Portuense"],
  ["marconi","Marconi"],["trullo","Trullo"],["corviale","Corviale"],["casetta-mattei","Casetta Mattei"],
  ["ponte-galeria","Ponte Galeria"],["piana-del-sole","Piana del Sole"],["villa-bonelli","Villa Bonelli"],
  ["aurelio","Aurelio"],["aurelia-antica","Aurelia Antica"],["gregorio-vii","Gregorio VII"],
  ["villa-carpegna","Villa Carpegna"],["boccea","Boccea"],["casalotti","Casalotti"],
  ["val-cannuta","Val Cannuta"],["valle-aurelia","Valle Aurelia"],["montespaccato","Montespaccato"],
  ["trastevere","Trastevere"],["porta-portese","Porta Portese"]
];
const HEADERS = {
  "user-agent": "Mozilla/5.0 (compatible; AhoRoma/1.0; +https://github.com/VitoPerillo/VitoPerillo.github.io)",
  "accept": "text/html,application/xhtml+xml",
  "accept-language": "it-IT,it;q=0.9,en;q=0.6",
};

function decode(s="") {
  return s.replace(/&nbsp;|&#160;/gi," ").replace(/&amp;/gi,"&").replace(/&quot;/gi,'"')
    .replace(/&#39;|&apos;/gi,"\'").replace(/&lt;/gi,"<").replace(/&gt;/gi,">")
    .replace(/&#(\\d+);/g,(_,n)=>String.fromCodePoint(Number(n))).replace(/\\s+/g," ").trim();
}
function strip(s="") {
  return decode(s.replace(/<script\\b[\\s\\S]*?<\\/script>/gi," ").replace(/<style\\b[\\s\\S]*?<\\/style>/gi," ").replace(/<[^>]+>/g," "));
}
function attr(tag,name) {
  const m=tag.match(new RegExp(name+"=[\\\"\\\']([^\\\"\\\']*)[\\\"\\\']","i"));
  return decode(m?.[1] || "");
}
function meta(html,key) {
  for (const m of html.matchAll(/<meta\\b[^>]*>/gi)) {
    const tag=m[0];
    if (attr(tag,"property")===key || attr(tag,"name")===key) return attr(tag,"content");
  }
  return "";
}
async function fetchHtml(url) {
  const r = await fetch(url, { headers: HEADERS, redirect: "follow", signal: AbortSignal.timeout(20000) });
  if (!r.ok) throw new Error(String(r.status)+" "+url);
  return r.text();
}
function articleLinks(html) {
  const out = new Set();
  for (const m of html.matchAll(/href=[\"\']([^\"\']*\\/web\\/it\\/notizia(?:\\/|\\.|\\?)[^\"\']*)[\"\']/gi)) {
    try {
      const u = new URL(decode(m[1]), BASE);
      if (u.hostname === "www.comune.roma.it") out.add(u.toString());
    } catch {}
  }
  return [...out].slice(0, 18);
}
function articleDate(html, plain) {
  const raw = meta(html,"search:data");
  if (/^\\d{12}$/.test(raw)) {
    const y=raw.slice(0,4),m=raw.slice(4,6),d=raw.slice(6,8),hh=raw.slice(8,10),mm=raw.slice(10,12);
    return y+"-"+m+"-"+d+"T"+hh+":"+mm+":00+02:00";
  }
  const months={gennaio:"01",febbraio:"02",marzo:"03",aprile:"04",maggio:"05",giugno:"06",luglio:"07",agosto:"08",settembre:"09",ottobre:"10",novembre:"11",dicembre:"12"};
  const x=plain.toLowerCase().match(/\\b(\\d{1,2})\\s+(gennaio|febbraio|marzo|aprile|maggio|giugno|luglio|agosto|settembre|ottobre|novembre|dicembre)\\s+(20\\d{2})\\b/);
  return x ? x[3]+"-"+months[x[2]]+"-"+String(x[1]).padStart(2,"0")+"T12:00:00+02:00" : null;
}
function areaSlug(text, fallback) {
  const low = text.toLocaleLowerCase("it-IT");
  const hits = NEIGHBORHOODS.filter(([,name])=>low.includes(name.toLocaleLowerCase("it-IT")));
  return hits.length === 1 ? hits[0][0] : fallback;
}
function categorySlug(text) {
  const s=text.toLowerCase();
  if (/mobilit|trasport|traffico|strad|bus|metro|parchegg|viabilit/.test(s)) return "viabilita-trasporti";
  if (/scuol|student|nido|educativ|libro|famigli/.test(s)) return "scuola-famiglie";
  if (/rifiut|ambiente|parco|verde|ama\\b|raccolta/.test(s)) return "ambiente-quartiere";
  if (/avvis|scadenz|apert|chius|prenot|bonus|servizio/.test(s)) return "avvisi-utili";
  return "servizi-municipio";
}

const candidates = new Map();
for (const [query, areaSlugValue, label] of LISTS) {
  const html = await fetchHtml(BASE+"/web/it/notizie.page?mun="+query);
  for (const url of articleLinks(html)) {
    const row = candidates.get(url) || { url, areas: [] };
    if (!row.areas.some(x=>x.areaSlugValue===areaSlugValue)) row.areas.push({areaSlugValue,label});
    candidates.set(url,row);
  }
}

const urls=[...candidates.keys()].slice(0,40);
const items=[];
for (let i=0;i<urls.length;i+=5) {
  const batch=urls.slice(i,i+5);
  const rows=await Promise.all(batch.map(async url=>{
    try {
      const html=await fetchHtml(url);
      const plain=strip(html);
      const h1=html.match(/<h1\\b[^>]*>([\\s\\S]*?)<\\/h1>/i);
      const title=meta(html,"og:title") || strip(h1?.[1] || "");
      const description=meta(html,"og:description") || meta(html,"description");
      if (!title || title.length < 12) return null;
      const c=candidates.get(url);
      if (!c?.areas?.length) return null;
      const uniqueAreas=[...new Set(c.areas.map(x=>x.areaSlugValue))];
      const context=title+" "+description+" "+plain.slice(0,5000);
      const area=areaSlug(context, uniqueAreas.length===1 ? uniqueAreas[0] : null);
      if (!area) return null;
      const label=c.areas.find(x=>x.areaSlugValue===uniqueAreas[0])?.label || "Roma";
      let text=decode(description);
      if (text.length < 80) {
        const body=plain.replace(title,"").trim();
        text=body.slice(0,420);
      }
      text=decode(label+". "+text).slice(0,700);
      if (text.length < 80) return null;
      return {
        id: url,
        url,
        title: decode(title).replace(/^Roma Capitale\\s*\\|\\s*/i,""),
        text,
        date: articleDate(html,plain),
        area_slug: area,
        category_slug: categorySlug(title+" "+text)
      };
    } catch (e) {
      console.warn("skip",url,String(e));
      return null;
    }
  }));
  items.push(...rows.filter(Boolean));
}

items.sort((a,b)=>String(b.date||"").localeCompare(String(a.date||"")));
const fresh=items.filter(x=>!x.date || Date.now()-Date.parse(x.date) < 14*86400000).slice(0,30);
if (fresh.length < 3) throw new Error("Feed safety gate failed: only "+fresh.length+" valid items");
await fs.mkdir(new URL("../public/feeds/",import.meta.url),{recursive:true});
await fs.writeFile(OUT, JSON.stringify(fresh,null,2)+"\\n","utf8");
console.log("Wrote "+fresh.length+" official Roma Capitale items");