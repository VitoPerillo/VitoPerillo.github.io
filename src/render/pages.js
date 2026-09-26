import { html } from "../core/utils.js";

const routeFor = (x) =>
  x.content_type === "event"
    ? `/eventi/${x.slug}`
    : x.content_type === "business"
      ? `/attivita/${x.slug}`
      : `/notizie/${x.slug}`;
const fmtDate = (x) => {
  try {
    return new Intl.DateTimeFormat("it-IT", {
      day: "2-digit",
      month: "short",
      hour: "2-digit",
      minute: "2-digit",
    }).format(new Date(String(x).replace(" ", "T") + "Z"));
  } catch {
    return String(x || "");
  }
};
const empty = (title, text) =>
  `<div class="empty"><strong>${html(title)}</strong><p>${html(text)}</p></div>`;

const shell = (title, body, meta = "") =>
  `<!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#0b5cff"><title>${html(title)}</title>${meta}<link rel="stylesheet" href="/app.css"></head><body><header class="site-header"><div class="wrap header-inner"><a class="brand" href="/"><span class="brand-mark">AHÓ</span><span><b>AHÓ ROMA</b><small>Che succede nel tuo quartiere?</small></span></a><nav><a href="/#ultime">Ultime</a><a href="/quartieri">Quartieri</a><a href="/eventi">Eventi</a><a class="nav-cta" href="/segnala">Segnala una notizia</a></nav></div></header><main>${body}</main><footer><div class="wrap footer-grid"><div><b>AHÓ ROMA</b><p>Notizie, eventi e informazioni utili vicino a te.</p></div><div><a href="/#ultime">Ultime</a> · <a href="/quartieri">Quartieri</a> · <a href="/eventi">Eventi</a> · <a href="/segnala">Segnala una notizia</a> · <a href="/pubblicita">Per le attività</a></div></div></footer></body></html>`;

async function houseAd(db) {
  try {
    return await db
      .prepare(
        "SELECT label,title,body,target_url FROM la_ads WHERE active=1 AND ad_type='house' AND (starts_at IS NULL OR starts_at<=CURRENT_TIMESTAMP) AND (ends_at IS NULL OR ends_at>=CURRENT_TIMESTAMP) ORDER BY priority ASC,id ASC LIMIT 1",
      )
      .first();
  } catch {
    return null;
  }
}
const renderAd = () => "";
const card = (x) =>
  `<article class="card"><div class="eyebrow">${html(x.area || x.category || "Roma Ovest")}</div><h3><a href="${html(routeFor(x))}">${html(x.title)}</a></h3><p>${html(x.summary || "")}</p><small>Aggiornato ${html(fmtDate(x.updated_at))}</small></article>`;
const articleBody = (value) =>
  String(value || "")
    .split(/\n{2,}/)
    .map((block) => {
      const text = block.trim();
      if (!text) return "";
      if (text.startsWith("## ")) return `<h2>${html(text.slice(3))}</h2>`;
      const lines = text
        .split("\n")
        .map((x) => x.trim())
        .filter(Boolean);
      if (lines.every((x) => x.startsWith("- ")))
        return `<ul>${lines.map((x) => `<li>${html(x.slice(2))}</li>`).join("")}</ul>`;
      return `<p>${html(text).replace(/\n/g, "<br>")}</p>`;
    })
    .join("");

export async function home(db) {
  const [latest, events, areas] = await Promise.all([
    db
      .prepare(
        "SELECT c.id,c.content_type,c.slug,c.title,c.summary,c.updated_at,a.name area,a.slug area_slug,k.name category FROM la_content c LEFT JOIN la_areas a ON a.id=c.area_id LEFT JOIN la_categories k ON k.id=c.category_id WHERE c.content_type='news' AND c.status IN ('published','updated') ORDER BY c.featured DESC,c.updated_at DESC LIMIT 12",
      )
      .all(),
    db
      .prepare(
        "SELECT c.id,c.content_type,c.slug,c.title,c.summary,c.updated_at,a.name area FROM la_content c LEFT JOIN la_areas a ON a.id=c.area_id WHERE c.content_type='event' AND c.status IN ('published','updated') AND (c.event_end IS NULL OR c.event_end>=CURRENT_TIMESTAMP) ORDER BY COALESCE(c.event_start,c.published_at) ASC LIMIT 4",
      )
      .all(),
    db
      .prepare(
        "SELECT name,slug FROM la_areas WHERE active=1 AND kind='neighborhood' ORDER BY name LIMIT 40",
      )
      .all(),
  ]);

  const news = latest.results || [];
  const lead = news[0] || null;
  const side = news.slice(1,4);
  const more = news.slice(4,10);
  const areaLinks = (areas.results || [])
    .map((a) => `<a class="chip" href="/zona/${html(a.slug)}">${html(a.name)}</a>`)
    .join("");

  const leadMarkup = lead
    ? `<article class="lead-story"><div class="eyebrow">${html(lead.area || lead.category || "Roma")}</div><h1><a href="${html(routeFor(lead))}">${html(lead.title)}</a></h1><p>${html(lead.summary || "")}</p><small>Aggiornato ${html(fmtDate(lead.updated_at))}</small></article>`
    : empty("AHÓ ROMA è online", "Le notizie verificate compariranno qui appena disponibili.");

  const sideMarkup = side.length
    ? side.map((x)=>`<article class="headline-row"><div class="eyebrow">${html(x.area || x.category || "Roma")}</div><h2><a href="${html(routeFor(x))}">${html(x.title)}</a></h2><small>${html(fmtDate(x.updated_at))}</small></article>`).join("")
    : "";

  const geoData = JSON.stringify([
    ["monteverde-vecchio","Monteverde Vecchio",41.8794,12.4527],
    ["monteverde-nuovo","Monteverde Nuovo",41.8728,12.4435],
    ["gianicolense","Gianicolense",41.8720,12.4490],
    ["colli-portuensi","Colli Portuensi",41.8508,12.4446],
    ["casaletto","Casaletto",41.8690,12.4380],
    ["bravetta","Bravetta",41.8725,12.4210],
    ["villa-pamphilj","Villa Pamphilj",41.8870,12.4450],
    ["pisana","Pisana",41.8590,12.4100],
    ["massimina","Massimina",41.8670,12.3560],
    ["casal-lumbroso","Casal Lumbroso",41.8730,12.3690],
    ["portuense","Portuense",41.8530,12.4570],
    ["marconi","Marconi",41.8530,12.4700],
    ["trullo","Trullo",41.8370,12.4360],
    ["corviale","Corviale",41.8290,12.4030],
    ["casetta-mattei","Casetta Mattei",41.8380,12.4150],
    ["ponte-galeria","Ponte Galeria",41.8220,12.3490],
    ["piana-del-sole","Piana del Sole",41.8170,12.3260],
    ["villa-bonelli","Villa Bonelli",41.8470,12.4560],
    ["aurelio","Aurelio",41.8990,12.4300],
    ["aurelia-antica","Aurelia Antica",41.8850,12.4350],
    ["gregorio-vii","Gregorio VII",41.8950,12.4490],
    ["villa-carpegna","Villa Carpegna",41.8990,12.4320],
    ["boccea","Boccea",41.9060,12.4080],
    ["casalotti","Casalotti",41.9190,12.3660],
    ["val-cannuta","Val Cannuta",41.8980,12.3990],
    ["valle-aurelia","Valle Aurelia",41.9030,12.4440],
    ["montespaccato","Montespaccato",41.9230,12.4020],
    ["trastevere","Trastevere",41.8890,12.4695],
    ["porta-portese","Porta Portese",41.8755,12.4725]
  ]);

  return new Response(
    shell(
      "AHÓ ROMA — Che succede nel tuo quartiere?",
      `
      <section class="news-intro">
        <div class="wrap news-intro-inner">
          <div>
            <span class="kicker">AHÓ ROMA</span>
            <h2>Che succede nel tuo quartiere?</h2>
            <p>Notizie, eventi e informazioni utili vicino a te.</p>
          </div>
          <div class="location-tools">
            <button class="location-btn" id="geoBtn" type="button">📍 Trova il mio quartiere</button>
            <a class="location-manual" href="/quartieri">Scegli manualmente</a>
            <span class="location-status" id="geoStatus" aria-live="polite"></span>
          </div>
        </div>
      </section>

      <section class="breaking wrap" id="ultime">
        <div class="section-head compact-head">
          <div><span class="kicker">ULTIME</span><h2>Le notizie adesso</h2></div>
          <a href="/quartieri">Cambia zona →</a>
        </div>
        <div class="news-front">
          ${leadMarkup}
          <div class="headline-list">${sideMarkup}</div>
        </div>
      </section>

      ${more.length ? `<section class="section wrap latest-grid"><div class="section-head"><div><span class="kicker">ALTRE NOTIZIE</span><h2>Dal territorio</h2></div></div><div class="grid cards">${more.map(card).join("")}</div></section>` : ""}

      <section class="section muted"><div class="wrap"><div class="section-head"><div><span class="kicker">QUARTIERI</span><h2>Vai direttamente nella tua zona</h2></div><a href="/quartieri">Tutti →</a></div><div class="chips">${areaLinks}</div></div></section>

      <section class="section wrap"><div class="section-head"><div><span class="kicker">EVENTI</span><h2>Cosa fare vicino a te</h2></div><a href="/eventi">Tutti gli eventi →</a></div><div class="grid cards">${(events.results || []).length ? (events.results || []).map(card).join("") : empty("Nessun evento ancora pubblicato", "Gli eventi verificati compariranno qui automaticamente.")}</div></section>

      <section class="section action-band"><div class="wrap action-grid"><div><h2>È successo qualcosa nel tuo quartiere?</h2><p>Segnala una notizia, un evento o un'informazione utile. La verifichiamo prima della pubblicazione.</p></div><a class="btn light" href="/segnala">Segnala una notizia →</a></div></section>

      <script>
      (() => {
        const areas = ${geoData};
        const btn = document.getElementById('geoBtn');
        const status = document.getElementById('geoStatus');
        const dist = (lat1,lon1,lat2,lon2) => {
          const r=6371, dLat=(lat2-lat1)*Math.PI/180, dLon=(lon2-lon1)*Math.PI/180;
          const a=Math.sin(dLat/2)**2+Math.cos(lat1*Math.PI/180)*Math.cos(lat2*Math.PI/180)*Math.sin(dLon/2)**2;
          return 2*r*Math.asin(Math.sqrt(a));
        };
        const saved = (()=>{try{return JSON.parse(localStorage.getItem('ahoRomaArea')||'null')}catch{return null}})();
        if(saved?.slug && saved?.name){
          btn.textContent='📍 '+saved.name;
          btn.onclick=()=>location.href='/zona/'+encodeURIComponent(saved.slug);
          status.innerHTML='<a href="/quartieri">Cambia quartiere</a>';
          return;
        }
        btn?.addEventListener('click', () => {
          if(!navigator.geolocation){
            status.textContent='Posizione non disponibile. Scegli il quartiere.';
            return;
          }
          btn.disabled=true; btn.textContent='📍 Sto cercando…';
          navigator.geolocation.getCurrentPosition(pos => {
            const lat=pos.coords.latitude, lon=pos.coords.longitude;
            const nearest=areas.map(a=>({slug:a[0],name:a[1],km:dist(lat,lon,a[2],a[3])})).sort((a,b)=>a.km-b.km)[0];
            btn.disabled=false;
            if(!nearest || nearest.km>18){
              btn.textContent='📍 Fuori area';
              status.innerHTML='Per ora copriamo Roma Ovest / Sud-Ovest. <a href="/quartieri">Scegli un quartiere</a>.';
              return;
            }
            localStorage.setItem('ahoRomaArea',JSON.stringify({slug:nearest.slug,name:nearest.name}));
            location.href='/zona/'+encodeURIComponent(nearest.slug);
          }, () => {
            btn.disabled=false; btn.textContent='📍 Trova il mio quartiere';
            status.innerHTML='Posizione non concessa. <a href="/quartieri">Scegli manualmente</a>.';
          }, {enableHighAccuracy:false,timeout:7000,maximumAge:900000});
        });
      })();
      </script>
    `,
    ),
    {
      headers: {
        "content-type": "text/html;charset=utf-8",
        "cache-control": "public,max-age=60",
      },
    },
  );
}

export async function contentPage(db, slug, baseUrl) {
  const x = await db
    .prepare(
      "SELECT c.*,a.name area,a.slug area_slug,k.name category FROM la_content c LEFT JOIN la_areas a ON a.id=c.area_id LEFT JOIN la_categories k ON k.id=c.category_id WHERE c.slug=? AND c.status IN ('published','updated','resolved')",
    )
    .bind(slug)
    .first();
  if (!x) return new Response("Not found", { status: 404 });
  const root = new URL(baseUrl).origin,
    canonicalUrl = root + routeFor(x);
  const [related, ad] = await Promise.all([
    db
      .prepare(
        "SELECT c.content_type,c.slug,c.title,c.summary,c.updated_at,a.name area FROM la_content c LEFT JOIN la_areas a ON a.id=c.area_id WHERE c.id<>? AND c.status IN ('published','updated') AND (c.area_id=? OR c.category_id=?) ORDER BY c.updated_at DESC LIMIT 4",
      )
      .bind(x.id, x.area_id, x.category_id)
      .all(),
    houseAd(db),
  ]);
  const rel = (related.results || []).map(card).join("");
  const imageUrl = x.image_key
    ? `${root}/media/${encodeURIComponent(x.image_key).replace(/%2F/g, "/")}`
    : null;
  const meta = `<link rel="canonical" href="${html(canonicalUrl)}"><meta name="description" content="${html(x.summary)}"><meta property="og:title" content="${html(x.title)}"><meta property="og:description" content="${html(x.summary)}"><meta property="og:url" content="${html(canonicalUrl)}">${imageUrl ? `<meta property="og:image" content="${html(imageUrl)}">` : ""}`;
  let schema;
  if (x.content_type === "event")
    schema = {
      "@context": "https://schema.org",
      "@type": "Event",
      name: x.title,
      description: x.summary,
      startDate: x.event_start || x.valid_from || undefined,
      endDate: x.event_end || x.valid_until || undefined,
      location: x.venue
        ? { "@type": "Place", name: x.venue, address: x.address || undefined }
        : undefined,
      url: canonicalUrl,
    };
  else if (x.content_type === "business")
    schema = {
      "@context": "https://schema.org",
      "@type": "LocalBusiness",
      name: x.title,
      description: x.summary,
      address: x.address || undefined,
      url: x.website || canonicalUrl,
      telephone: x.phone || undefined,
    };
  else
    schema = {
      "@context": "https://schema.org",
      "@type": "NewsArticle",
      headline: x.title,
      datePublished: x.published_at,
      dateModified: x.updated_at,
      description: x.summary,
      image: imageUrl || undefined,
      author: { "@type": "Organization", name: "AHÓ ROMA" },
      mainEntityOfPage: canonicalUrl,
    };
  const crumbs = `<nav class="breadcrumbs" aria-label="breadcrumb"><a href="/">Home</a>${x.area_slug ? ` › <a href="/zona/${html(x.area_slug)}">${html(x.area)}</a>` : ""} › ${html(x.title)}</nav>`;
  const facts = [
    x.area,
    x.category,
    x.event_start ? fmtDate(x.event_start) : null,
    x.venue,
    x.price_type,
  ]
    .filter(Boolean)
    .map((v) => `<span>${html(v)}</span>`)
    .join("");
  return new Response(
    shell(
      x.title,
      `<div class="wrap article-wrap">${crumbs}<article class="article"><div class="eyebrow">${html(x.area || "Roma Ovest / Sud-Ovest")}</div><h1>${html(x.title)}</h1><div class="facts">${facts}</div><div class="impact"><span>COSA CAMBIA PER TE</span><p>${html(x.summary)}</p></div>${x.image_key ? `<figure><img class="article-image" src="/media/${html(x.image_key)}" alt="Illustrazione della notizia locale per ${html(x.area || "Roma Ovest")}" loading="lazy"><figcaption>Illustrazione originale AHÓ ROMA · <a href="https://creativecommons.org/licenses/by/4.0/deed.it" rel="license noopener">CC BY 4.0</a></figcaption></figure>` : ""}<div class="article-body">${articleBody(x.body)}</div><p class="source">Sintesi originale revisionata a partire da una fonte ufficiale.${x.source_url ? ` <a rel="nofollow noopener" href="${html(x.source_url)}">Consulta la pagina integrale ↗</a>` : ""} · Aggiornato ${html(fmtDate(x.updated_at))}</p><details class="report-box"><summary>Segnala un problema con questo contenuto</summary><form onsubmit="return reportContent(event,${Number(x.id)})"><label>Motivo<select name="reason"><option value="false_info">Informazione errata</option><option value="privacy">Privacy o dati personali</option><option value="copyright">Diritti d'autore</option><option value="offensive">Contenuto offensivo</option><option value="illegal">Possibile illecito</option><option value="spam">Spam</option><option value="other">Altro</option></select></label><label>Dettagli<textarea name="details" maxlength="2000" required></textarea></label><label>Email facoltativa<input name="email" type="email"></label><button>Invia segnalazione</button><output></output></form></details><script>async function reportContent(e,id){e.preventDefault();const f=e.target,r=await fetch('/api/report',{method:'POST',headers:{'content-type':'application/json'},body:JSON.stringify({...Object.fromEntries(new FormData(f)),content_id:id})});f.querySelector('output').textContent=r.ok?'Segnalazione ricevuta. Grazie.':'Invio non riuscito.';if(r.ok)f.reset();return false}</script><script type="application/ld+json">${JSON.stringify(schema).replace(/</g, "\\u003c")}</script></article>${renderAd(ad)}${rel ? `<section class="section"><div class="section-head"><h2>Contenuti correlati</h2></div><div class="grid cards">${rel}</div></section>` : ""}</div>`,
      meta,
    ),
    {
      headers: {
        "content-type": "text/html;charset=utf-8",
        "cache-control": "public,max-age=300",
      },
    },
  );
}

export async function areaPage(db, slug) {
  const area = await db
    .prepare("SELECT id,name,slug FROM la_areas WHERE slug=? AND active=1")
    .bind(slug)
    .first();
  if (!area) return new Response("Not found", { status: 404 });
  const q = await db
    .prepare(
      "SELECT c.content_type,c.slug,c.title,c.summary,c.updated_at,a.name area FROM la_content c LEFT JOIN la_areas a ON a.id=c.area_id WHERE c.area_id=? AND c.status IN ('published','updated') ORDER BY c.updated_at DESC LIMIT 50",
    )
    .bind(area.id)
    .all();
  const list = (q.results || []).map(card).join("");
  return new Response(
    shell(
      `${area.name} — AHÓ ROMA`,
      `<section class="area-hero"><div class="wrap"><nav class="breadcrumbs"><a href="/">Home</a> › <a href="/quartieri">Quartieri</a> › ${html(area.name)}</nav><span class="kicker">NOTIZIE DI QUARTIERE</span><h1>${html(area.name)}</h1><p>Tutto ciò che può essere utile oggi in questa zona: viabilità, servizi, eventi e attività locali.</p></div></section><section class="section wrap"><div class="grid cards">${list || empty("Nessun aggiornamento pubblicato", "Le fonti vengono controllate automaticamente. Torna presto o invia una segnalazione utile.")}</div></section>`,
    ),
    {
      headers: {
        "content-type": "text/html;charset=utf-8",
        "cache-control": "public,max-age=300",
      },
    },
  );
}

export async function listPage(db, type) {
  if (type === "areas") {
    const q = await db
      .prepare(
        "SELECT a.name,a.slug,p.name parent FROM la_areas a LEFT JOIN la_areas p ON p.id=a.parent_id WHERE a.active=1 AND a.kind='neighborhood' ORDER BY a.name",
      )
      .all();
    const items = (q.results || [])
      .map(
        (x) =>
          `<a class="area-card" href="/zona/${html(x.slug)}"><strong>${html(x.name)}</strong><small>${html(x.parent || "Roma Ovest / Sud-Ovest")}</small><span>Apri →</span></a>`,
      )
      .join("");
    return new Response(
      shell(
        "Quartieri — AHÓ ROMA",
        `<section class="area-hero"><div class="wrap"><span class="kicker">ROMA OVEST / SUD-OVEST</span><h1>Scegli il tuo quartiere</h1><p>La copertura segue le zone reali della città, non un semplice raggio.</p></div></section><section class="section wrap"><div class="area-grid">${items}</div></section>`,
      ),
      { headers: { "content-type": "text/html;charset=utf-8" } },
    );
  }
  const contentType = type === "events" ? "event" : "business";
  const title =
    contentType === "event" ? "Eventi vicino a te" : "Attività locali";
  const q = await db
    .prepare(
      "SELECT c.id,c.content_type,c.slug,c.title,c.summary,c.updated_at,a.name area FROM la_content c LEFT JOIN la_areas a ON a.id=c.area_id WHERE c.content_type=? AND c.status IN ('published','updated') ORDER BY c.featured DESC,c.updated_at DESC LIMIT 100",
    )
    .bind(contentType)
    .all();
  const items = (q.results || []).map(card).join("");
  return new Response(
    shell(
      `${title} — AHÓ ROMA`,
      `<section class="area-hero"><div class="wrap"><span class="kicker">ROMA OVEST / SUD-OVEST</span><h1>${html(title)}</h1><p>${contentType === "event" ? "Cosa fare oggi, domani e nel weekend." : "Scopri servizi e attività del territorio."}</p></div></section><section class="section wrap"><div class="grid cards">${items || empty("Ancora nessun contenuto", "La sezione è online e pronta a ricevere contenuti verificati.")}</div></section>`,
    ),
    { headers: { "content-type": "text/html;charset=utf-8" } },
  );
}

export async function sitemap(db, base, newsOnly = false) {
  const root = new URL(base).origin;
  const where = newsOnly
    ? "content_type='news' AND published_at >= datetime('now','-2 days') AND status IN ('published','updated')"
    : "status IN ('published','updated','resolved')";
  const q = await db
    .prepare(
      `SELECT content_type,slug,updated_at FROM la_content WHERE ${where} ORDER BY updated_at DESC LIMIT 5000`,
    )
    .all();
  let urls = (q.results || [])
    .map(
      (x) =>
        `<url><loc>${html(root + routeFor(x))}</loc><lastmod>${new Date(String(x.updated_at).replace(" ", "T") + "Z").toISOString()}</lastmod></url>`,
    )
    .join("");
  if (!newsOnly) {
    const a = await db
      .prepare(
        "SELECT slug FROM la_areas WHERE active=1 AND kind='neighborhood' ORDER BY id LIMIT 1000",
      )
      .all();
    urls += (a.results || [])
      .map((x) => `<url><loc>${html(root + "/zona/" + x.slug)}</loc></url>`)
      .join("");
    urls += ["/", "/quartieri", "/eventi", "/attivita", "/pubblicita"]
      .map((p) => `<url><loc>${html(root + p)}</loc></url>`)
      .join("");
  }
  return new Response(
    `<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">${urls}</urlset>`,
    { headers: { "content-type": "application/xml;charset=utf-8" } },
  );
}
