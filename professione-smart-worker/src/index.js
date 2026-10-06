import { PROVIDER_PHOTO_B64 } from "./provider-photo.js";\nimport { HOME_PHOTO_B64 } from "./home-photo.generated.js";

const BASE_URL = "https://professione-smart.black-sea-41df.workers.dev";
const PLATFORM_FEE_RATE = 0.005;
const SESSION_TTL_SECONDS = 60 * 60 * 24 * 30;

const SCOPES = [
  "organizations.read",
  "profiles.read",
  "onboarding.read",
  "payments.read", "payments.write",
  "refunds.read", "refunds.write",
  "terminals.read", "terminals.write"
].join(" ");

function esc(v) {
  return String(v ?? "").replace(/[&<>"']/g, c => ({
    "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;"
  })[c]);
}

function page(body, opts = {}) {
  const status = opts.status || 200;
  const path = opts.path || "/";
  const title = opts.title || "POS sullo smartphone a 0 € al mese | Professione Smart";
  const description = opts.description || "Trasforma uno smartphone compatibile in un POS contactless. 0 € di canone mensile; si applicano commissioni per transazione.";
  const canonical = BASE_URL + path;
  const robots = opts.robots || (["/", "/attiva", "/commissioni", "/privacy"].includes(path) ? "index,follow" : "noindex,nofollow");
  const extraHeaders = opts.headers || {};
  const brandHtml = opts.hideBrand ? "" : '<a class="brand" href="/">Professione Smart</a>';
  const doc = '<!doctype html><html lang="it"><head><meta charset="utf-8">' +
    '<meta name="viewport" content="width=device-width,initial-scale=1">' +
    '<meta name="description" content="' + esc(description) + '">' +
    '<meta name="robots" content="' + esc(robots) + '">' +
    '<link rel="canonical" href="' + esc(canonical) + '">' +
    '<meta property="og:type" content="website"><meta property="og:locale" content="it_IT">' +
    '<meta property="og:site_name" content="Professione Smart">' +
    '<meta property="og:title" content="' + esc(title) + '">' +
    '<meta property="og:description" content="' + esc(description) + '">' +
    '<meta property="og:url" content="' + esc(canonical) + '">' +
    '<meta name="twitter:card" content="summary"><title>' + esc(title) + '</title>' +
    '<style>' +
    '*{box-sizing:border-box}body{margin:0;background:#f6f8fb;color:#18202a;font-family:Inter,Arial,sans-serif;line-height:1.55}' +
    '.wrap{max-width:1050px;margin:auto;padding:26px 22px 56px}.brand{display:inline-block;color:#18202a;text-decoration:none;font-weight:900;font-size:21px;letter-spacing:-.4px;margin-bottom:34px}' +
    '.hero{display:grid;grid-template-columns:1.02fr .98fr;gap:24px;align-items:center;background:linear-gradient(135deg,#111827,#243247);color:#fff;border-radius:28px;padding:30px;box-shadow:0 18px 50px #10182822}.hero-link{color:#fff;text-decoration:none;cursor:pointer}.hero-copy{min-width:0}.hero-photo{width:100%;aspect-ratio:4/3;object-fit:cover;border-radius:24px;display:block;box-shadow:0 18px 46px #0006}' +
    '.eyebrow{font-size:13px;font-weight:800;text-transform:uppercase;letter-spacing:1.4px;color:#b9d9ff}.hero h1{font-size:clamp(38px,7vw,72px);line-height:.98;letter-spacing:-2.5px;margin:12px 0 20px;max-width:820px}.hero p{font-size:20px;max-width:760px;color:#e6edf7}' +
    '.cta{display:inline-block;margin-top:14px;background:#fff;color:#111827;text-decoration:none;font-weight:900;padding:16px 22px;border-radius:12px}.cta.dark{background:#111827;color:#fff}.small{font-size:13px;color:#64748b}' +
    '.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin:22px 0}.card{background:#fff;border:1px solid #e5eaf0;border-radius:20px;padding:25px}.card h2,.card h3{margin-top:0}.action-card{display:block;color:#18202a;text-decoration:none;cursor:pointer}.action-card:hover{box-shadow:0 10px 24px #10182812;transform:translateY(-1px)}.section{padding:34px 4px 8px}' +
    '.provider-page{max-width:1040px;margin:12px auto 0}.provider-head{text-align:center;margin-bottom:26px}.provider-head .eyebrow{color:#64748b}.provider-head h1{font-size:clamp(38px,5.4vw,64px);line-height:1.02;letter-spacing:-2px;margin:8px auto 12px;max-width:820px}.provider-head p{font-size:18px;color:#64748b;margin:0}.provider-stage{display:grid;grid-template-columns:1.25fr .9fr;gap:24px;align-items:stretch}.provider-photo{width:100%;height:100%;min-height:390px;object-fit:cover;border-radius:26px;display:block;box-shadow:0 18px 44px #1018281c}.provider-choices{display:grid;gap:14px;align-content:center}.provider-card{display:block;text-decoration:none;color:#111827;background:#fff;border:1px solid #dfe5ec;border-radius:22px;padding:26px;box-shadow:0 10px 28px #10182810;transition:.18s ease}.provider-card:hover{transform:translateY(-2px);box-shadow:0 16px 35px #1018281c;border-color:#bcc7d4}.provider-card h3{font-size:30px;margin:0 0 12px}.provider-cta{display:inline-flex;background:#111827;color:#fff;border-radius:12px;padding:13px 17px;font-weight:900}.provider-commission{text-align:center;margin:22px 0 0}.provider-commission a{color:#475569}' +
    '.steps{counter-reset:s}.step{display:flex;gap:16px;margin:18px 0}.step:before{counter-increment:s;content:counter(s);min-width:34px;height:34px;border-radius:50%;display:grid;place-items:center;background:#111827;color:#fff;font-weight:900}' +
    '.trust{background:#eef4ff;border-radius:20px;padding:22px;margin-top:24px}.formbox{max-width:720px;margin:0 auto;background:#fff;border:1px solid #e5eaf0;border-radius:24px;padding:30px;box-shadow:0 14px 36px #10182812}.formbox h1{margin-top:0;font-size:clamp(32px,5vw,52px);line-height:1}' +
    '.field{margin:16px 0}.field label{display:block;font-weight:800;margin-bottom:7px}.field input,.field select{width:100%;padding:14px 15px;border:1px solid #cbd5e1;border-radius:11px;font:inherit;background:#fff}.check{display:flex;gap:10px;align-items:flex-start;font-size:14px;color:#475569}.check input{margin-top:4px}' +
    '.submit{width:100%;border:0;background:#111827;color:#fff;padding:16px 20px;border-radius:12px;font-weight:900;font-size:16px;cursor:pointer;margin-top:18px}.notice{background:#fff7ed;border:1px solid #fed7aa;border-radius:14px;padding:14px 16px}.success{background:#ecfdf5;border:1px solid #a7f3d0;border-radius:14px;padding:14px 16px}.code{font-size:26px;font-weight:900;letter-spacing:2px;word-break:break-all}.qr{max-width:260px;width:100%;height:auto;border-radius:14px;border:1px solid #e5e7eb}' +
    '.footer{margin-top:35px;padding-top:20px;border-top:1px solid #dde3ea;font-size:13px;color:#64748b}@media(max-width:760px){.wrap{padding:18px 15px 40px}.hero{grid-template-columns:1fr;padding:18px;border-radius:22px}.hero-photo{order:-1;border-radius:18px}.hero p{font-size:18px}.grid{grid-template-columns:1fr}.hero h1{letter-spacing:-1.5px}.formbox{padding:22px}.provider-page{margin-top:0}.provider-head{margin-bottom:18px}.provider-head h1{font-size:40px;letter-spacing:-1.5px}.provider-head p{font-size:16px}.provider-stage{grid-template-columns:1fr}.provider-photo{min-height:0;aspect-ratio:4/3}.provider-choices{grid-template-columns:1fr 1fr;gap:10px}.provider-card{padding:18px 14px}.provider-card h3{font-size:23px}.provider-cta{font-size:12px;padding:11px 12px}}' +
    '</style></head><body><main class="wrap">' + brandHtml + body + '</main></body></html>';
  return new Response(doc, {
    status,
    headers: {
      "content-type": "text/html; charset=UTF-8",
      "cache-control": "no-store",
      "x-content-type-options": "nosniff",
      "referrer-policy": "strict-origin-when-cross-origin",
      ...extraHeaders
    }
  });
}

function bytesToB64(bytes) {
  let s = "";
  for (const b of bytes) s += String.fromCharCode(b);
  return btoa(s);
}
function b64ToBytes(s) {
  const b = atob(s);
  return Uint8Array.from(b, c => c.charCodeAt(0));
}
function b64url(bytes) {
  return bytesToB64(bytes).replaceAll("+", "-").replaceAll("/", "_").replaceAll("=", "");
}
function fromB64url(s) {
  let v = s.replaceAll("-", "+").replaceAll("_", "/");
  while (v.length % 4) v += "=";
  return b64ToBytes(v);
}
async function aesKey(secret) {
  const digest = await crypto.subtle.digest("SHA-256", new TextEncoder().encode(secret));
  return crypto.subtle.importKey("raw", digest, { name: "AES-GCM" }, false, ["encrypt", "decrypt"]);
}
async function encrypt(value, secret) {
  const iv = crypto.getRandomValues(new Uint8Array(12));
  const key = await aesKey(secret);
  const ct = await crypto.subtle.encrypt({ name: "AES-GCM", iv }, key, new TextEncoder().encode(value));
  return { data: bytesToB64(new Uint8Array(ct)), iv: bytesToB64(iv) };
}
async function decrypt(data, iv, secret) {
  const key = await aesKey(secret);
  const pt = await crypto.subtle.decrypt({ name: "AES-GCM", iv: b64ToBytes(iv) }, key, b64ToBytes(data));
  return new TextDecoder().decode(pt);
}
async function hmacKey(secret) {
  return crypto.subtle.importKey("raw", new TextEncoder().encode(secret), { name: "HMAC", hash: "SHA-256" }, false, ["sign", "verify"]);
}
async function createSessionCookie(orgId, secret) {
  const payload = b64url(new TextEncoder().encode(JSON.stringify({
    org: orgId, exp: Math.floor(Date.now() / 1000) + SESSION_TTL_SECONDS
  })));
  const key = await hmacKey(secret);
  const sig = b64url(new Uint8Array(await crypto.subtle.sign("HMAC", key, new TextEncoder().encode(payload))));
  return "ps_session=" + payload + "." + sig + "; Path=/; HttpOnly; Secure; SameSite=Lax; Max-Age=" + SESSION_TTL_SECONDS;
}
function cookieValue(request, name) {
  const cookie = request.headers.get("cookie") || "";
  const m = cookie.match(new RegExp("(?:^|;\\s*)" + name.replace(/[.*+?^${}()|[\]\\]/g, "\\$&") + "=([^;]+)"));
  return m ? m[1] : "";
}
async function sessionOrg(request, secret) {
  const raw = cookieValue(request, "ps_session");
  if (!raw || !raw.includes(".")) return "";
  const [payload, sig] = raw.split(".", 2);
  try {
    const key = await hmacKey(secret);
    const ok = await crypto.subtle.verify("HMAC", key, fromB64url(sig), new TextEncoder().encode(payload));
    if (!ok) return "";
    const obj = JSON.parse(new TextDecoder().decode(fromB64url(payload)));
    if (!obj.org || !obj.exp || obj.exp < Math.floor(Date.now() / 1000)) return "";
    return String(obj.org);
  } catch {
    return "";
  }
}
function sameOrigin(request) {
  const origin = request.headers.get("origin");
  return !origin || origin === BASE_URL;
}

async function track(env, eventName, url, leadId = "", extra = {}) {
  try {
    const now = Math.floor(Date.now() / 1000);
    await env.DB.prepare("INSERT INTO events (id,event_name,lead_id,source,medium,campaign,path,created_at) VALUES(?,?,?,?,?,?,?,?)")
      .bind(
        crypto.randomUUID(), eventName, leadId || null,
        extra.source || url.searchParams.get("utm_source") || "",
        extra.medium || url.searchParams.get("utm_medium") || "",
        extra.campaign || url.searchParams.get("utm_campaign") || "",
        url.pathname, now
      ).run();
  } catch {}
}

async function getConnection(env, orgId) {
  return env.DB.prepare("SELECT * FROM oauth_connections WHERE organization_id=?").bind(orgId).first();
}
async function refreshAccessToken(env, row) {
  const refresh = await decrypt(row.refresh_token_enc, row.iv_refresh, env.MOLLIE_CLIENT_SECRET);
  const basic = btoa(env.MOLLIE_CLIENT_ID + ":" + env.MOLLIE_CLIENT_SECRET);
  const resp = await fetch("https://api.mollie.com/oauth2/tokens", {
    method: "POST",
    headers: { "authorization": "Basic " + basic, "content-type": "application/x-www-form-urlencoded" },
    body: new URLSearchParams({ grant_type: "refresh_token", refresh_token: refresh })
  });
  if (!resp.ok) throw new Error("refresh_failed_" + resp.status);
  const tokens = await resp.json();
  const ea = await encrypt(tokens.access_token, env.MOLLIE_CLIENT_SECRET);
  const newRefresh = tokens.refresh_token || refresh;
  const er = await encrypt(newRefresh, env.MOLLIE_CLIENT_SECRET);
  const now = Math.floor(Date.now() / 1000);
  const expires = now + (tokens.expires_in || 3600);
  await env.DB.prepare("UPDATE oauth_connections SET access_token_enc=?,refresh_token_enc=?,iv_access=?,iv_refresh=?,scope=?,expires_at=?,updated_at=? WHERE organization_id=?")
    .bind(ea.data, er.data, ea.iv, er.iv, tokens.scope || row.scope || "", expires, now, row.organization_id).run();
  return tokens.access_token;
}
async function getAccessToken(env, orgId, force = false) {
  const row = await getConnection(env, orgId);
  if (!row) throw new Error("connection_not_found");
  const now = Math.floor(Date.now() / 1000);
  if (!force && row.expires_at && row.expires_at > now + 90) {
    return decrypt(row.access_token_enc, row.iv_access, env.MOLLIE_CLIENT_SECRET);
  }
  return refreshAccessToken(env, row);
}
async function mollieFetch(env, orgId, path, init = {}) {
  let token = await getAccessToken(env, orgId, false);
  let resp = await fetch("https://api.mollie.com" + path, {
    ...init,
    headers: { ...(init.headers || {}), "authorization": "Bearer " + token }
  });
  if (resp.status === 401) {
    token = await getAccessToken(env, orgId, true);
    resp = await fetch("https://api.mollie.com" + path, {
      ...init,
      headers: { ...(init.headers || {}), "authorization": "Bearer " + token }
    });
  }
  return resp;
}
async function getProfiles(env, orgId) {
  const resp = await mollieFetch(env, orgId, "/v2/profiles?limit=250");
  if (!resp.ok) throw new Error("profiles_" + resp.status);
  const j = await resp.json();
  return j._embedded?.profiles || [];
}
async function getTerminals(env, orgId) {
  const resp = await mollieFetch(env, orgId, "/v2/terminals?limit=250");
  if (!resp.ok) throw new Error("terminals_" + resp.status);
  const j = await resp.json();
  return j._embedded?.terminals || [];
}
function parseAmountCents(v) {
  const s = String(v || "").trim().replace(",", ".");
  if (!/^\d+(?:\.\d{1,2})?$/.test(s)) return 0;
  const [euros, cents = ""] = s.split(".");
  const n = Number(euros) * 100 + Number((cents + "00").slice(0, 2));
  return Number.isSafeInteger(n) ? n : 0;
}
function eur(cents) {
  return (cents / 100).toFixed(2);
}
function platformFeeCents(amountCents) {
  return Math.max(1, Math.round(amountCents * PLATFORM_FEE_RATE));
}
function profileLabel(p) {
  return p.name || p.website || p.id;
}
function terminalLabel(t) {
  return (t.description || t.brand || "Mollie Tap / POS") + " — " + t.id + (t.status ? " (" + t.status + ")" : "");
}

function home(url) {
  const qs = url.search || "";
  return page(
    '<a class="hero hero-link" href="/attiva' + esc(qs) + '" aria-label="Attiva il tuo smartphone come POS ora">' +
      '<div class="hero-copy">' +
        '<div class="eyebrow">Il POS semplice per professionisti e piccole attività</div>' +
        '<h1>Il tuo POS è già nel tuo smartphone.</h1>' +
        '<p><strong>0 € di canone mensile.</strong> Accetta pagamenti contactless direttamente dal tuo iPhone o Android compatibile, senza comprare un POS tradizionale.</p>' +
        '<span class="cta">ATTIVA IL TUO SMARTPHONE COME POS ORA</span>' +
      '</div>' +
      '<img class="hero-photo" src="/home-photo.webp" alt="Pagamento contactless su smartphone in un salone di bellezza">' +
    '</a>' +
    '<section class="section"><h2>Perché può convenire</h2><div class="grid">' +
      '<div class="card"><h3>Niente hardware extra</h3><p>Usa uno smartphone compatibile come terminale contactless.</p></div>' +
      '<div class="card"><h3>0 € di canone mensile</h3><p>Nessun abbonamento mensile: paghi solo quando incassi.</p></div>' +
      '<div class="card"><h3>Incasso sul tuo account</h3><p>Incassi tramite il provider di pagamento che scegli durante l’attivazione.</p></div>' +
    '</div></section>' +
    '<section class="section" style="padding-top:22px"><h2>Come funziona</h2><div class="grid" style="grid-template-columns:repeat(2,1fr);margin:12px 0 4px">' +
      '<a class="card action-card" href="/attiva' + esc(qs) + '"><h3>1. Attiva il POS sul tuo smartphone</h3><p>Inserisci i tuoi dati e completa la procedura guidata.</p></a>' +
      '<a class="card action-card" href="/attiva' + esc(qs) + '"><h3>2. Incassa</h3><p>Digita l’importo e avvicina la carta o lo smartphone del cliente al tuo smartphone.</p></a>' +
    '</div><a class="cta dark" href="/attiva' + esc(qs) + '">ATTIVA IL POS SUL TUO SMARTPHONE</a></section>' +
    '<section class="section"><h2>Domande frequenti</h2>' +
      '<div class="card"><h3>Posso usare iPhone o Android?</h3><p>Sì, su dispositivi compatibili. I requisiti tecnici vengono verificati in base al provider scelto durante l’attivazione.</p></div>' +
      '<div class="card"><h3>Serve comprare un terminale?</h3><p>No, se il tuo smartphone è compatibile puoi usare il Tap to Pay direttamente sul dispositivo.</p></div>' +
      '<div class="card"><h3>Chi gestisce i pagamenti?</h3><p>In base alla scelta effettuata durante l’attivazione, i pagamenti vengono gestiti da Mollie oppure Stripe.</p></div>' +
      '<div class="card"><h3>Ci sono commissioni?</h3><p>Sì. Non c’è alcun canone mensile, ma si applicano commissioni sulle transazioni. <a href="/commissioni">Consulta le commissioni complete</a>.</p></div>' +
    '</section>' +
    '<div class="trust"><strong>Trasparenza prima di tutto.</strong> Nessun canone mensile. Le commissioni sulle transazioni sono pubbliche e consultabili in qualsiasi momento: <a href="/commissioni">vedi le commissioni</a>.</div>' +
    '<footer class="footer">Professione Smart · Servizi digitali per professionisti e piccole attività · <a href="/commissioni">Commissioni</a> · <a href="/privacy">Privacy</a></footer>',
    { path: "/" }
  );
}

export default {
  async fetch(request, env) {
    const url = new URL(request.url);

    if (url.pathname === "/health") return new Response("ok", { headers: { "cache-control": "no-store" } });
    if (url.pathname === "/provider-photo.webp") {
      return new Response(b64ToBytes(PROVIDER_PHOTO_B64), { headers: { "content-type": "image/webp", "cache-control": "public, max-age=31536000, immutable" } });
    }
    if (url.pathname === "/home-photo.webp") {
      return new Response(b64ToBytes(HOME_PHOTO_B64), { headers: { "content-type": "image/webp", "cache-control": "public, max-age=31536000, immutable" } });
    }
    if (url.pathname === "/robots.txt") {
      return new Response("User-agent: *\nAllow: /\nSitemap: " + BASE_URL + "/sitemap.xml\n", { headers: { "content-type": "text/plain; charset=UTF-8" } });
    }
    if (url.pathname === "/sitemap.xml") {
      return new Response('<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>' + BASE_URL + '/</loc></url><url><loc>' + BASE_URL + '/attiva</loc></url><url><loc>' + BASE_URL + '/commissioni</loc></url><url><loc>' + BASE_URL + '/privacy</loc></url></urlset>', { headers: { "content-type": "application/xml; charset=UTF-8" } });
    }

    if (url.pathname === "/privacy") {
      return page(
        '<section class="formbox"><h1>Privacy</h1>' +
        '<p>Professione Smart usa i dati inseriti nel modulo esclusivamente per gestire la richiesta di attivazione, predisporre il collegamento al fornitore di pagamento e fornire assistenza sul percorso richiesto.</p>' +
        '<h2>Dati trattati</h2><p>Nome, cognome, email, nome dell’attività e dati tecnici strettamente necessari al funzionamento del servizio.</p>' +
        '<h2>Fornitore di pagamento</h2><p>Per completare l’attivazione e gestire i pagamenti, i dati necessari possono essere trasmessi al provider scelto, Mollie o Stripe, che li tratta secondo le proprie condizioni e informative.</p>' +
        '<h2>Conservazione</h2><p>I dati vengono conservati per il tempo necessario a gestire la richiesta e il servizio, salvo obblighi di legge.</p>' +
        '<h2>Diritti e contatto</h2><p>Puoi chiedere accesso, correzione o cancellazione dei dati scrivendo a <a href="mailto:otachecker+professionesmart@gmail.com">otachecker+professionesmart@gmail.com</a>.</p>' +
        '<p><a href="/">Torna a Professione Smart</a></p></section>',
        { path: "/privacy", title: "Privacy | Professione Smart" }
      );
    }

    if (url.pathname === "/commissioni") {
      return page(
        '<section class="formbox"><div class="eyebrow" style="color:#475569">Costi trasparenti</div>' +
        '<h1>Commissioni</h1>' +
        '<p><strong>Canone mensile Professione Smart: 0 €.</strong></p>' +
        '<p>Le tariffe qui sotto indicano il <strong>costo totale per il professionista</strong> sui pagamenti Tap to Pay standard, già comprensivo del servizio Professione Smart.</p>' +
        '<div class="card"><h2>Mollie · Pay as you go</h2>' +
        '<p>Carte di debito e credito consumer nazionali: <strong>1,70%</strong></p>' +
        '<p>Altre carte consumer: <strong>2,30%</strong></p>' +
        '<p>American Express: <strong>2,60%</strong></p>' +
        '<p>Carte commerciali: <strong>3,40%</strong></p>' +
        '<p class="small">Piano senza canone mensile. Disponibilità dei circuiti soggetta alle condizioni Mollie.</p></div>' +
        '<div class="card" style="margin-top:16px"><h2>Stripe · Tap to Pay</h2>' +
        '<p>Carte SEE: <strong>1,90% + 0,20 €</strong></p>' +
        '<p>Carte non SEE: <strong>3,40% + 0,20 €</strong></p>' +
        '<p class="small">Il totale include la tariffa standard Stripe Terminal, l’autorizzazione Tap to Pay e il servizio Professione Smart.</p></div>' +
        '<div class="notice" style="margin-top:16px"><strong>Aggiornamento: 2 ottobre 2026.</strong> Le tariffe dei provider possono cambiare o variare in presenza di condizioni personalizzate, servizi extra o tipologie di carta particolari.</div>' +
        '<p><a href="https://www.mollie.com/it/pricing" target="_blank" rel="noopener">Tariffe ufficiali Mollie</a> · <a href="https://stripe.com/it/pricing" target="_blank" rel="noopener">Tariffe ufficiali Stripe</a></p>' +
        '<p><a class="cta dark" href="/attiva">ATTIVA IL POS</a></p></section>',
        { path: "/commissioni", title: "Commissioni | Professione Smart", description: "Commissioni totali per i pagamenti Tap to Pay con Professione Smart, Mollie e Stripe. Canone mensile Professione Smart: 0 €." }
      );
    }

    if (url.pathname === "/attiva" && request.method === "GET") {
      await track(env, "funnel_start", url);
      const provider = String(url.searchParams.get("provider") || "").toLowerCase();

      if (provider !== "stripe" && provider !== "mollie") {
        return page(
          '<section class="provider-page">' +
          '<div class="provider-head"><div class="eyebrow">Scegli il provider</div>' +
          '<h1>Attiva il tuo smartphone come POS</h1>' +
          '<p>Scegli il provider di pagamento che preferisci.</p></div>' +
          '<div class="provider-stage">' +
          '<img class="provider-photo" src="/provider-photo.webp" alt="Pagamento contactless con carta su smartphone">' +
          '<div class="provider-choices">' +
          '<a class="provider-card" href="/attiva?provider=stripe"><h3>Stripe</h3><span class="provider-cta">ATTIVA CON STRIPE</span></a>' +
          '<a class="provider-card" href="/attiva?provider=mollie"><h3>Mollie</h3><span class="provider-cta">ATTIVA CON MOLLIE</span></a>' +
          '</div></div>' +
          '<p class="provider-commission"><a href="/commissioni">Confronta le commissioni complete</a></p></section>',
          { path: "/attiva", title: "Scegli Stripe o Mollie | Professione Smart", hideBrand: true }
        );
      }

      const providerName = provider === "stripe" ? "Stripe" : "Mollie";
      const stripeNotice = provider === "stripe"
        ? '<div class="notice"><strong>Stripe:</strong> il percorso è disponibile, ma gli incassi reali saranno abilitati solo dopo il completamento delle verifiche richieste da Stripe. <a href="/commissioni">Vedi le commissioni</a>.</div>'
        : '<div class="notice"><strong>0 € di canone mensile.</strong> <a href="/commissioni">Consulta le commissioni complete</a>.</div>';

      return page(
        '<section class="formbox"><div class="eyebrow" style="color:#475569">Attiva con ' + providerName + '</div>' +
        '<h1>0 € di canone mensile.</h1>' +
        '<p>Inserisci questi dati: li useremo per preparare la procedura di attivazione con ' + providerName + ' e ridurre al minimo quello che dovrai riscrivere.</p>' +
        stripeNotice +
        '<form method="post" action="/attiva">' +
        '<input type="hidden" name="provider" value="' + provider + '">' +
        '<input type="hidden" name="utm_source" value="' + esc(url.searchParams.get("utm_source")) + '">' +
        '<input type="hidden" name="utm_medium" value="' + esc(url.searchParams.get("utm_medium")) + '">' +
        '<input type="hidden" name="utm_campaign" value="' + esc(url.searchParams.get("utm_campaign")) + '">' +
        '<div class="field"><label for="given_name">Nome</label><input id="given_name" name="given_name" autocomplete="given-name" required></div>' +
        '<div class="field"><label for="family_name">Cognome</label><input id="family_name" name="family_name" autocomplete="family-name" required></div>' +
        '<div class="field"><label for="email">Email</label><input id="email" name="email" type="email" autocomplete="email" required></div>' +
        '<div class="field"><label for="organization_name">Nome attività</label><input id="organization_name" name="organization_name" autocomplete="organization" required></div>' +
        '<label class="check"><input type="checkbox" name="consent" value="yes" required><span>Ho letto l’<a href="/privacy" target="_blank" rel="noopener">informativa privacy</a> e chiedo di avviare la procedura di attivazione e collegamento a ' + providerName + '.</span></label>' +
        '<button class="submit" type="submit">CONTINUA CON ' + providerName.toUpperCase() + '</button></form>' +
        '<p class="small">0 € di canone mensile. <a href="/commissioni">Consulta le commissioni complete</a>.</p></section>',
        { path: "/attiva", title: "Attiva con " + providerName + " | Professione Smart" }
      );
    }

    if (url.pathname === "/attiva" && request.method === "POST") {
      if (!sameOrigin(request)) return page("<h2>Richiesta non valida</h2>", { status: 403, path: "/attiva" });
      const form = await request.formData();
      const provider = String(form.get("provider") || "").trim().toLowerCase();
      if (provider !== "stripe" && provider !== "mollie") {
        return page("<h2>Provider non valido</h2><p>Torna indietro e scegli Stripe oppure Mollie.</p>", { status: 400, path: "/attiva" });
      }
      const givenName = String(form.get("given_name") || "").trim();
      const familyName = String(form.get("family_name") || "").trim();
      const email = String(form.get("email") || "").trim().toLowerCase();
      const organizationName = String(form.get("organization_name") || "").trim();
      const consent = String(form.get("consent") || "");
      const utmSource = String(form.get("utm_source") || "").trim().slice(0, 120);
      const utmMedium = String(form.get("utm_medium") || "").trim().slice(0, 120);
      const utmCampaign = String(form.get("utm_campaign") || "").trim().slice(0, 120);
      if (!givenName || !familyName || !email || !organizationName || consent !== "yes") {
        return page("<h2>Dati incompleti</h2><p>Compila tutti i campi e riprova.</p>", { status: 400, path: "/attiva" });
      }

      if (provider === "stripe") {
        const leadId = crypto.randomUUID();
        const now = Math.floor(Date.now() / 1000);
        await env.DB.prepare("INSERT INTO leads (id,given_name,family_name,email,organization_name,source,status,consent_at,created_at,updated_at) VALUES(?,?,?,?,?,?, 'stripe_waiting',?,?,?)")
          .bind(leadId, givenName, familyName, email, organizationName, utmSource || "direct", now, now, now).run();
        await track(env, "stripe_lead_captured", url, leadId, { source: utmSource, medium: utmMedium, campaign: utmCampaign });
        return page(
          '<section class="formbox"><div class="eyebrow" style="color:#475569">Stripe selezionato</div>' +
          '<h1>Richiesta registrata.</h1>' +
          '<p>Abbiamo salvato i dati per il collegamento Stripe. Il passaggio successivo sarà l’onboarding Stripe Connect.</p>' +
          '<div class="notice"><strong>Nessun pagamento è stato attivato.</strong> Gli incassi reali resteranno disabilitati finché le verifiche Stripe non saranno completate.</div>' +
          '<p><a class="cta" href="/">Torna a Professione Smart</a></p></section>',
          { path: "/attiva", title: "Stripe selezionato | Professione Smart" }
        );
      }

      if (!env.MOLLIE_ADVANCED_ACCESS_TOKEN) {
        return page("<h2>Configurazione Mollie in corso</h2><p>Riprova tra poco.</p>", { status: 503, path: "/attiva" });
      }

      const leadId = crypto.randomUUID();
      const now = Math.floor(Date.now() / 1000);
      await env.DB.prepare("INSERT INTO leads (id,given_name,family_name,email,organization_name,source,status,consent_at,created_at,updated_at) VALUES(?,?,?,?,?,?, 'captured',?,?,?)")
        .bind(leadId, givenName, familyName, email, organizationName, utmSource || "direct", now, now, now).run();
      await track(env, "lead_captured", url, leadId, { source: utmSource, medium: utmMedium, campaign: utmCampaign });
      const clientResp = await fetch("https://api.mollie.com/v2/client-links", {
        method: "POST",
        headers: { "authorization": "Bearer " + env.MOLLIE_ADVANCED_ACCESS_TOKEN, "content-type": "application/x-www-form-urlencoded" },
        body: new URLSearchParams({
          "owner[email]": email,
          "owner[givenName]": givenName,
          "owner[familyName]": familyName,
          "owner[locale]": "it_IT",
          "address[country]": "IT",
          "name": organizationName
        })
      });
      const client = await clientResp.json().catch(() => ({}));
      if (!clientResp.ok || !client._links?.clientLink?.href) {
        await env.DB.prepare("UPDATE leads SET status='client_link_error',updated_at=? WHERE id=?").bind(now, leadId).run();
        return page("<h2>Non siamo riusciti ad avviare l’attivazione</h2><p>Riprova tra qualche minuto.</p>", { status: 502, path: "/attiva" });
      }
      const nonce = b64url(crypto.getRandomValues(new Uint8Array(24)));
      const state = nonce + "." + leadId;
      const dest = new URL(client._links.clientLink.href);
      dest.searchParams.set("client_id", env.MOLLIE_CLIENT_ID);
      dest.searchParams.set("redirect_uri", env.MOLLIE_REDIRECT_URI);
      dest.searchParams.set("response_type", "code");
      dest.searchParams.set("scope", SCOPES);
      dest.searchParams.set("state", state);
      await env.DB.prepare("UPDATE leads SET status='client_link_created',updated_at=? WHERE id=?").bind(now, leadId).run();
      await track(env, "client_link_created", url, leadId, { source: utmSource, medium: utmMedium, campaign: utmCampaign });
      return new Response(null, {
        status: 302,
        headers: {
          "location": dest.toString(),
          "set-cookie": "ps_oauth_state=" + state + "; Path=/; HttpOnly; Secure; SameSite=Lax; Max-Age=1800"
        }
      });
    }

    if (url.pathname === "/connect") {
      if (!env.MOLLIE_CLIENT_SECRET) return page("<h2>Configurazione in corso</h2><p>Il collegamento Mollie non è ancora pronto.</p>", { status: 503, path: "/connect" });
      const state = b64url(crypto.getRandomValues(new Uint8Array(24)));
      const q = new URLSearchParams({
        client_id: env.MOLLIE_CLIENT_ID,
        redirect_uri: env.MOLLIE_REDIRECT_URI,
        state,
        scope: SCOPES,
        response_type: "code",
        locale: "it_IT",
        landing_page: "signup"
      });
      return new Response(null, {
        status: 302,
        headers: {
          "location": "https://my.mollie.com/oauth2/authorize?" + q.toString(),
          "set-cookie": "ps_oauth_state=" + state + "; Path=/; HttpOnly; Secure; SameSite=Lax; Max-Age=600"
        }
      });
    }

    if (url.pathname === "/mollie/callback") {
      const err = url.searchParams.get("error");
      if (err) return page("<h2>Collegamento non completato</h2><p>" + esc(err) + "</p>", { status: 400, path: "/mollie/callback" });
      const code = url.searchParams.get("code");
      const state = url.searchParams.get("state");
      const saved = cookieValue(request, "ps_oauth_state");
      if (!code || !state || !saved || state !== saved) {
        return page("<h2>Collegamento non valido</h2><p>Riprova dalla pagina Professione Smart.</p>", { status: 400, path: "/mollie/callback" });
      }
      if (!env.MOLLIE_CLIENT_SECRET) return page("<h2>Configurazione non completa</h2><p>Manca la configurazione privata Mollie della piattaforma.</p>", { status: 503, path: "/mollie/callback" });
      const basic = btoa(env.MOLLIE_CLIENT_ID + ":" + env.MOLLIE_CLIENT_SECRET);
      const tokenResp = await fetch("https://api.mollie.com/oauth2/tokens", {
        method: "POST",
        headers: { "authorization": "Basic " + basic, "content-type": "application/x-www-form-urlencoded" },
        body: new URLSearchParams({ grant_type: "authorization_code", code, redirect_uri: env.MOLLIE_REDIRECT_URI })
      });
      if (!tokenResp.ok) return page("<h2>Collegamento non riuscito</h2><p>Mollie non ha accettato l’autorizzazione. Riprova.</p>", { status: 502, path: "/mollie/callback" });
      const tokens = await tokenResp.json();
      const orgResp = await fetch("https://api.mollie.com/v2/organizations/me", { headers: { "authorization": "Bearer " + tokens.access_token } });
      if (!orgResp.ok) return page("<h2>Autorizzazione ricevuta</h2><p>Impossibile leggere l’organizzazione collegata.</p>", { status: 502, path: "/mollie/callback" });
      const org = await orgResp.json();
      const ea = await encrypt(tokens.access_token, env.MOLLIE_CLIENT_SECRET);
      const er = await encrypt(tokens.refresh_token, env.MOLLIE_CLIENT_SECRET);
      const now = Math.floor(Date.now() / 1000);
      const expires = now + (tokens.expires_in || 3600);
      await env.DB.prepare("INSERT INTO oauth_connections (organization_id,organization_name,access_token_enc,refresh_token_enc,iv_access,iv_refresh,scope,expires_at,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?) ON CONFLICT(organization_id) DO UPDATE SET organization_name=excluded.organization_name,access_token_enc=excluded.access_token_enc,refresh_token_enc=excluded.refresh_token_enc,iv_access=excluded.iv_access,iv_refresh=excluded.iv_refresh,scope=excluded.scope,expires_at=excluded.expires_at,updated_at=excluded.updated_at")
        .bind(org.id, org.name || "", ea.data, er.data, ea.iv, er.iv, tokens.scope || "", expires, now, now).run();
      const leadId = state.includes(".") ? state.split(".").pop() : "";
      if (leadId) {
        await env.DB.prepare("UPDATE leads SET status='connected',organization_id=?,updated_at=? WHERE id=?").bind(org.id, now, leadId).run();
        await track(env, "merchant_connected", url, leadId);
      }
      const session = await createSessionCookie(org.id, env.MOLLIE_CLIENT_SECRET);
      return new Response(null, {
        status: 302,
        headers: {
          "location": BASE_URL + "/mollie/tap",
          "set-cookie": session
        }
      });
    }

    if (url.pathname === "/mollie/tap") {
      const orgId = await sessionOrg(request, env.MOLLIE_CLIENT_SECRET);
      if (!orgId) {
        return page(
          '<section class="formbox"><h1>Collega Mollie</h1><p>Per configurare Mollie Tap devi prima autorizzare Professione Smart ad accedere al tuo account Mollie.</p><a class="cta dark" href="/connect">COLLEGA MOLLIE</a></section>',
          { path: "/mollie/tap", title: "Configura Mollie Tap | Professione Smart" }
        );
      }
      try {
        const profiles = await getProfiles(env, orgId);
        const terminals = await getTerminals(env, orgId);
        if (request.method === "POST") {
          if (!sameOrigin(request)) return page("<h2>Richiesta non valida</h2>", { status: 403, path: "/mollie/tap" });
          const form = await request.formData();
          const profileId = String(form.get("profileId") || "");
          if (!profiles.some(p => p.id === profileId)) return page("<h2>Profilo Mollie non valido</h2>", { status: 400, path: "/mollie/tap" });
          const resp = await mollieFetch(env, orgId, "/v2/terminals/pairing-codes?include=details.qrCode", {
            method: "POST",
            headers: { "content-type": "application/json" },
            body: JSON.stringify({ profileId })
          });
          const pairing = await resp.json().catch(() => ({}));
          if (!resp.ok) {
            const detail = pairing.detail || pairing.title || ("Errore Mollie " + resp.status);
            return page(
              '<section class="formbox"><h1>Non posso ancora creare il codice Tap</h1><div class="notice">' + esc(detail) + '</div><p>Il POS deve essere abilitato da Mollie per questa organizzazione e il collegamento deve avere il permesso <strong>terminals.write</strong>.</p><p><a href="/mollie/tap">Riprova</a></p></section>',
              { status: resp.status, path: "/mollie/tap", title: "Configura Mollie Tap | Professione Smart" }
            );
          }
          const code = pairing.code || pairing.pairingCode || "";
          const qr = pairing.details?.qrCode || "";
          const qrHtml = typeof qr === "string" && qr.startsWith("data:image") ? '<p><img class="qr" src="' + esc(qr) + '" alt="QR code per abbinare Mollie Tap"></p>' : "";
          return page(
            '<section class="formbox"><div class="eyebrow" style="color:#475569">Mollie Tap</div><h1>Abbina questo smartphone</h1>' +
            '<div class="success"><strong>Codice creato da Mollie.</strong> Apri l’app Mollie Tap sul telefono.</div>' +
            qrHtml +
            (code ? '<p>Codice di configurazione:</p><div class="code">' + esc(code) + '</div>' : "") +
            '<div class="steps"><div class="step"><div><strong>Apri Mollie Tap</strong> e scegli “Start setup”.</div></div><div class="step"><div><strong>Scansiona il QR</strong> oppure inserisci il codice qui sopra.</div></div><div class="step"><div><strong>Accetta i permessi</strong> richiesti dall’app e completa la configurazione.</div></div></div>' +
            '<p class="small">Il codice di pairing è generato direttamente da Mollie e scade secondo le regole Mollie. Non condividere il codice con persone non autorizzate.</p>' +
            '<a class="cta dark" href="/mollie/tap">HO COMPLETATO L’ABBINAMENTO</a></section>',
            { path: "/mollie/tap", title: "Abbina Mollie Tap | Professione Smart" }
          );
        }
        const profileOptions = profiles.map(p => '<option value="' + esc(p.id) + '">' + esc(profileLabel(p)) + '</option>').join("");
        const terminalCards = terminals.length
          ? terminals.map(t => '<div class="card"><strong>' + esc(terminalLabel(t)) + '</strong><br><span class="small">Profilo: ' + esc(t.profileId || "—") + '</span></div>').join("")
          : '<div class="notice">Nessun terminale/Tap ancora abbinato a questo account.</div>';
        return page(
          '<section class="formbox"><div class="eyebrow" style="color:#475569">Mollie Tap</div><h1>Configura il telefono come POS</h1>' +
          '<p>Il tuo account Mollie è collegato. Ora puoi generare direttamente un codice/QR ufficiale Mollie per abbinare l’app Mollie Tap.</p>' +
          terminalCards +
          '<form method="post" action="/mollie/tap"><div class="field"><label for="profileId">Profilo Mollie</label><select id="profileId" name="profileId" required>' + profileOptions + '</select></div>' +
          '<button class="submit" type="submit">GENERA CODICE MOLLIE TAP</button></form>' +
          (terminals.length ? '<p><a class="cta dark" href="/cassa">APRI LA CASSA PROFESSIONE SMART</a></p>' : "") +
          '<p class="small">L’abbinamento usa il servizio ufficiale Mollie. Nessuna transazione viene creata finché non inserisci un importo nella cassa e confermi l’incasso.</p></section>',
          { path: "/mollie/tap", title: "Configura Mollie Tap | Professione Smart" }
        );
      } catch (e) {
        return page(
          '<section class="formbox"><h1>Ricollega Mollie</h1><div class="notice">Il collegamento Mollie deve essere aggiornato per usare le funzioni POS/Tap.</div><a class="cta dark" href="/connect">RICOLLEGA MOLLIE</a></section>',
          { status: 502, path: "/mollie/tap", title: "Configura Mollie Tap | Professione Smart" }
        );
      }
    }

    if (url.pathname === "/cassa") {
      const orgId = await sessionOrg(request, env.MOLLIE_CLIENT_SECRET);
      if (!orgId) return page('<section class="formbox"><h1>Collega Mollie</h1><p>Prima collega il tuo account Mollie.</p><a class="cta dark" href="/connect">COLLEGA MOLLIE</a></section>', { path: "/cassa", title: "Cassa | Professione Smart" });
      try {
        const profiles = await getProfiles(env, orgId);
        const terminals = await getTerminals(env, orgId);
        if (request.method === "POST") {
          if (!sameOrigin(request)) return page("<h2>Richiesta non valida</h2>", { status: 403, path: "/cassa" });
          const form = await request.formData();
          const amountCents = parseAmountCents(form.get("amount"));
          const profileId = String(form.get("profileId") || "");
          const terminalId = String(form.get("terminalId") || "");
          if (amountCents < 200) {
            return page('<section class="formbox"><h1>Importo non valido</h1><div class="notice">L’importo minimo supportato dalla Cassa Professione Smart è 2,00 €.</div><p><a href="/cassa">Torna alla cassa</a></p></section>', { status: 400, path: "/cassa", title: "Cassa | Professione Smart" });
          }
          if (!profiles.some(p => p.id === profileId) || !terminals.some(t => t.id === terminalId)) {
            return page("<h2>Profilo o terminale non valido</h2>", { status: 400, path: "/cassa", title: "Cassa | Professione Smart" });
          }
          const feeCents = platformFeeCents(amountCents);
          const body = {
            amount: { currency: "EUR", value: eur(amountCents) },
            description: "Incasso Professione Smart",
            method: "pointofsale",
            profileId,
            terminalId,
            redirectUrl: BASE_URL + "/cassa",
            webhookUrl: BASE_URL + "/mollie/payment-webhook",
            applicationFee: {
              amount: { currency: "EUR", value: eur(feeCents) },
              description: "Servizio Professione Smart"
            }
          };
          const resp = await mollieFetch(env, orgId, "/v2/payments", {
            method: "POST",
            headers: { "content-type": "application/json" },
            body: JSON.stringify(body)
          });
          const payment = await resp.json().catch(() => ({}));
          if (!resp.ok) {
            return page('<section class="formbox"><h1>Pagamento non avviato</h1><div class="notice">' + esc(payment.detail || payment.title || ("Errore Mollie " + resp.status)) + '</div><p><a href="/cassa">Riprova</a></p></section>', { status: resp.status, path: "/cassa", title: "Cassa | Professione Smart" });
          }
          const now = Math.floor(Date.now() / 1000);
          await env.DB.prepare("INSERT OR REPLACE INTO pos_payments (id,organization_id,profile_id,terminal_id,amount_cents,fee_cents,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?)")
            .bind(payment.id, orgId, profileId, terminalId, amountCents, feeCents, payment.status || "open", now, now).run();
          return new Response(null, { status: 303, headers: { "location": BASE_URL + "/cassa/stato?id=" + encodeURIComponent(payment.id) } });
        }
        if (!profiles.length || !terminals.length) {
          return page('<section class="formbox"><h1>Prima abbina Mollie Tap</h1><p>Non trovo ancora un profilo o un terminale disponibile.</p><a class="cta dark" href="/mollie/tap">CONFIGURA MOLLIE TAP</a></section>', { path: "/cassa", title: "Cassa | Professione Smart" });
        }
        const profileOptions = profiles.map(p => '<option value="' + esc(p.id) + '">' + esc(profileLabel(p)) + '</option>').join("");
        const terminalOptions = terminals.map(t => '<option value="' + esc(t.id) + '">' + esc(terminalLabel(t)) + '</option>').join("");
        return page(
          '<section class="formbox"><div class="eyebrow" style="color:#475569">Cassa Professione Smart</div><h1>Quanto devi incassare?</h1>' +
          '<div class="success"><strong>0 € di canone mensile.</strong><br>Le commissioni applicabili sono quelle pubblicate nella pagina Commissioni.</div>' +
          '<form method="post" action="/cassa"><div class="field"><label for="amount">Importo (€)</label><input id="amount" name="amount" inputmode="decimal" placeholder="es. 25,00" required></div>' +
          '<div class="field"><label for="profileId">Profilo Mollie</label><select id="profileId" name="profileId" required>' + profileOptions + '</select></div>' +
          '<div class="field"><label for="terminalId">Telefono / terminale</label><select id="terminalId" name="terminalId" required>' + terminalOptions + '</select></div>' +
          '<button class="submit" type="submit">INCASSA CON MOLLIE TAP</button></form>' +
          '<p class="small">L’importo minimo supportato da questa cassa è 2,00 €. <a href="/commissioni">Consulta le commissioni</a>.</p></section>',
          { path: "/cassa", title: "Cassa | Professione Smart" }
        );
      } catch (e) {
        return page('<section class="formbox"><h1>Ricollega Mollie</h1><div class="notice">Il collegamento Mollie non è disponibile o deve essere aggiornato.</div><a class="cta dark" href="/connect">RICOLLEGA MOLLIE</a></section>', { status: 502, path: "/cassa", title: "Cassa | Professione Smart" });
      }
    }

    if (url.pathname === "/cassa/stato") {
      const orgId = await sessionOrg(request, env.MOLLIE_CLIENT_SECRET);
      const id = String(url.searchParams.get("id") || "");
      if (!orgId || !id) return page("<h2>Pagamento non disponibile</h2>", { status: 404, path: "/cassa/stato" });
      const row = await env.DB.prepare("SELECT * FROM pos_payments WHERE id=? AND organization_id=?").bind(id, orgId).first();
      if (!row) return page("<h2>Pagamento non trovato</h2>", { status: 404, path: "/cassa/stato" });
      try {
        const resp = await mollieFetch(env, orgId, "/v2/payments/" + encodeURIComponent(id));
        const payment = resp.ok ? await resp.json() : { status: row.status };
        const status = payment.status || row.status;
        const now = Math.floor(Date.now() / 1000);
        await env.DB.prepare("UPDATE pos_payments SET status=?,updated_at=? WHERE id=?").bind(status, now, id).run();
        const paid = status === "paid";
        return page(
          '<section class="formbox"><div class="eyebrow" style="color:#475569">Pagamento</div><h1>' + (paid ? "Pagamento ricevuto" : "Stato: " + esc(status)) + '</h1>' +
          '<div class="' + (paid ? "success" : "notice") + '">Importo: <strong>' + esc(eur(row.amount_cents)) + ' €</strong><br><a href="/commissioni">Consulta le commissioni applicabili</a></div>' +
          (paid ? '<p>Il pagamento risulta completato su Mollie.</p>' : '<p>Se il cliente sta ancora avvicinando carta o wallet al telefono, attendi qualche secondo e aggiorna lo stato.</p><p><a class="cta dark" href="/cassa/stato?id=' + encodeURIComponent(id) + '">AGGIORNA STATO</a></p>') +
          '<p><a href="/cassa">Nuovo incasso</a></p></section>',
          { path: "/cassa/stato", title: "Stato pagamento | Professione Smart" }
        );
      } catch {
        return page('<section class="formbox"><h1>Stato temporaneamente non disponibile</h1><p><a href="/cassa/stato?id=' + encodeURIComponent(id) + '">Riprova</a></p></section>', { status: 502, path: "/cassa/stato", title: "Stato pagamento | Professione Smart" });
      }
    }

    if (url.pathname === "/mollie/payment-webhook" && request.method === "POST") {
      let id = "";
      const ct = request.headers.get("content-type") || "";
      try {
        if (ct.includes("application/json")) {
          const j = await request.json();
          id = String(j.id || "");
        } else {
          const f = await request.formData();
          id = String(f.get("id") || "");
        }
      } catch {}
      if (!id) return new Response("ok", { status: 200 });
      const row = await env.DB.prepare("SELECT organization_id FROM pos_payments WHERE id=?").bind(id).first();
      if (!row) return new Response("ok", { status: 200 });
      try {
        const resp = await mollieFetch(env, row.organization_id, "/v2/payments/" + encodeURIComponent(id));
        if (resp.ok) {
          const payment = await resp.json();
          await env.DB.prepare("UPDATE pos_payments SET status=?,updated_at=? WHERE id=?")
            .bind(payment.status || "unknown", Math.floor(Date.now() / 1000), id).run();
        }
      } catch {}
      return new Response("ok", { status: 200 });
    }

    if (url.pathname !== "/") return page('<h2>Pagina non trovata</h2><p><a href="/">Torna a Professione Smart</a></p>', { status: 404, path: url.pathname });
    await track(env, "landing_view", url);
    return home(url);
  }
};