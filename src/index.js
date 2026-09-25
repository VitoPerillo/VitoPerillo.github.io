import {
  responseJson,
  sha256,
  randomToken,
  nowIso,
  safeJson,
  html,
  hmacToken,
} from "./core/utils.js";
import { fetchSource } from "./sources/adapters.js";
import { ingestItem, processIngest, log } from "./core/pipeline.js";
import {
  enqueue,
  recoverStale,
  claimOne,
  complete,
  retryOrFail,
} from "./core/queue.js";
import { BrevoEmail } from "./email/brevo.js";
import {
  requireAdmin,
  rateLimit,
  validUpload,
  stripImageMetadata,
  safeSourceUrl,
  validFormTiming,
} from "./security/security.js";
import { riskGate, ugcModerationGate } from "./core/gates.js";
import {
  home,
  contentPage,
  areaPage,
  listPage,
  sitemap,
} from "./render/pages.js";
import { socialProvider } from "./social/adapter.js";
import { MediaStorageAdapter } from "./media/adapter.js";
import { fetchArticleDetails, reviewFactSheet } from "./core/article.js";

export default {
  async fetch(request, env, ctx) {
    const url = new URL(request.url),
      p = url.pathname;
    try {
      if (request.method === "GET" && p === "/") return home(env.DB);
      if (request.method === "GET" && p.startsWith("/notizie/"))
        return contentPage(env.DB, decodeURIComponent(p.slice(9)), request.url);
      if (request.method === "GET" && p.startsWith("/eventi/"))
        return contentPage(env.DB, decodeURIComponent(p.slice(8)), request.url);
      if (request.method === "GET" && p.startsWith("/attivita/"))
        return contentPage(
          env.DB,
          decodeURIComponent(p.slice(10)),
          request.url,
        );
      if (request.method === "GET" && p.startsWith("/zona/"))
        return areaPage(env.DB, decodeURIComponent(p.slice(6)));
      if (request.method === "GET" && p === "/quartieri")
        return listPage(env.DB, "areas");
      if (request.method === "GET" && p === "/eventi")
        return listPage(env.DB, "events");
      if (request.method === "GET" && p === "/attivita")
        return listPage(env.DB, "businesses");
      if (request.method === "GET" && p === "/sitemap.xml")
        return sitemap(env.DB, request.url, false);
      if (request.method === "GET" && p === "/news-sitemap.xml")
        return sitemap(env.DB, request.url, true);
      if (request.method === "GET" && p === "/api/areas")
        return listSimple(env.DB, "la_areas");
      if (request.method === "GET" && p === "/api/categories")
        return listSimple(env.DB, "la_categories");
      if (request.method === "GET" && p.startsWith("/media/"))
        return mediaGet(env, decodeURIComponent(p.slice(7)));
      if (request.method === "POST" && p === "/api/submission")
        return submission(request, env);
      if (request.method === "GET" && p === "/api/verify")
        return verifySubmission(request, env);
      if (request.method === "POST" && p === "/api/report")
        return reportContent(request, env);
      if (request.method === "POST" && p === "/api/ad-order")
        return adOrder(request, env);
      if (request.method === "POST" && p === "/api/manage")
        return manageSubmission(request, env);
      if (p.startsWith("/api/admin/")) return adminRoute(request, env, p);
      if (
        env.ASSETS &&
        request.method === "GET" &&
        ["/segnala", "/gestisci", "/admin", "/pubblicita"].includes(p)
      ) {
        const a = new URL(request.url);
        a.pathname = p + ".html";
        return env.ASSETS.fetch(
          new Request(a.toString(), {
            method: "GET",
            headers: request.headers,
          }),
        );
      }
      if (env.ASSETS) return env.ASSETS.fetch(request);
      return new Response("Not found", { status: 404 });
    } catch (e) {
      ctx?.waitUntil?.(log(env.DB, "error", "fetch", String(e).slice(0, 300)));
      return responseJson({ error: "internal_error" }, 500);
    }
  },
  async scheduled(controller, env, ctx) {
    const cron = controller.cron || "";
    if (cron === "17 3 * * *") ctx.waitUntil(maintenance(env));
    else ctx.waitUntil(tick(env));
  },
};

async function listSimple(db, table) {
  const allowed = new Set(["la_areas", "la_categories"]);
  if (!allowed.has(table)) return responseJson({ error: "bad_table" }, 400);
  const cols =
    table === "la_categories" ? "id,name,slug,kind" : "id,name,slug,kind";
  const q = await db
    .prepare(`SELECT ${cols} FROM ${table} WHERE active=1 ORDER BY name`)
    .all();
  return responseJson(q.results || []);
}
async function tick(env) {
  await recoverStale(env.DB);
  await sourceTick(env);
  await prepareDiscoveryReview(env.DB);
  const max = Math.max(1, Math.min(10, Number(env.MAX_QUEUE_BATCH || 8)));
  for (let i = 0; i < max; i++) {
    const job = await claimOne(env.DB);
    if (!job) break;
    try {
      await processJob(env, job);
      await complete(env.DB, job);
    } catch (e) {
      await retryOrFail(env.DB, job, e);
      await log(env.DB, "warning", "queue", String(e), {
        job_id: job.id,
        job_type: job.job_type,
      });
    }
  }
  await env.DB.prepare(
    "INSERT INTO la_settings(key,value) VALUES('queue_heartbeat',CURRENT_TIMESTAMP) ON CONFLICT(key) DO UPDATE SET value=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP",
  ).run();
}
async function sourceTick(env) {
  const q = await env.DB.prepare(
    "SELECT * FROM la_sources WHERE active=1 AND usage_policy!='blocked' AND (last_checked_at IS NULL OR datetime(last_checked_at, '+'||interval_minutes||' minutes') <= CURRENT_TIMESTAMP) ORDER BY COALESCE(last_checked_at,'1970-01-01') LIMIT 5",
  ).all();
  for (const s of q.results || []) {
    await env.DB.prepare(
      "UPDATE la_sources SET last_checked_at=CURRENT_TIMESTAMP WHERE id=?",
    )
      .bind(s.id)
      .run();
    try {
      const items = await fetchSource(s);
      for (const item of items) {
        const id = await ingestItem(env.DB, s, item);
        if (id) {
          if (s.usage_policy === "discovery")
            await env.DB.prepare(
              "UPDATE la_ingest SET status='held',updated_at=CURRENT_TIMESTAMP WHERE id=?",
            )
              .bind(id)
              .run();
          else await enqueue(env.DB, "process_ingest", id, {}, 100);
        }
      }
      await env.DB.prepare(
        "UPDATE la_sources SET last_success_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=?",
      )
        .bind(s.id)
        .run();
    } catch (e) {
      await log(env.DB, "warning", "source", String(e), { source_id: s.id });
    }
  }
}
async function prepareDiscoveryReview(db) {
  const moved = await db
    .prepare(
      "UPDATE la_ingest SET status='held',updated_at=CURRENT_TIMESTAMP WHERE status='new' AND source_id IN (SELECT id FROM la_sources WHERE usage_policy='discovery')",
    )
    .run();
  const jobs = await db
    .prepare(
      "UPDATE la_jobs SET status='completed',updated_at=CURRENT_TIMESTAMP,last_error=NULL WHERE job_type='process_ingest' AND status IN ('pending','retry') AND entity_id IN (SELECT id FROM la_ingest WHERE status IN ('held','published','updated','rejected'))",
    )
    .run();
  return {
    moved: Number(moved.meta?.changes || 0),
    jobs: Number(jobs.meta?.changes || 0),
  };
}
async function processJob(env, job) {
  if (job.job_type === "process_ingest")
    return processIngest(env, job.entity_id);
  if (job.job_type === "submission_review")
    return reviewSubmission(env, job.entity_id);
  if (job.job_type === "send_verify_email")
    return sendVerifyEmail(env, job.entity_id);
  if (job.job_type === "social_publish")
    return socialProvider().publish(job.payload);
  throw new Error("unknown_job_type");
}

async function readBody(request) {
  const ct = request.headers.get("content-type") || "";
  if (ct.includes("multipart/form-data")) {
    const fd = await request.formData();
    return {
      data: Object.fromEntries(
        [...fd.entries()].filter(([k, v]) => typeof v === "string"),
      ),
      file: fd.get("image") instanceof File ? fd.get("image") : null,
    };
  }
  return { data: await request.json(), file: null };
}
async function submission(request, env) {
  if (!(await rateLimit(env.DB, request, "submission", 5, 3600)))
    return responseJson({ error: "rate_limited" }, 429);
  const { data, file } = await readBody(request);
  if (data.website_hp) return responseJson({ ok: true }, 202);
  if (!validFormTiming(data.form_started_at))
    return responseJson({ error: "invalid_form_timing" }, 400);
  if (String(data.rights_declared || "") !== "1")
    return responseJson({ error: "rights_required" }, 400);
  const type = String(data.submission_type || "");
  if (!["event", "business", "report"].includes(type))
    return responseJson({ error: "invalid_type" }, 400);
  const email = String(data.email || "")
    .trim()
    .toLowerCase();
  if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email))
    return responseJson({ error: "invalid_email" }, 400);
  if (!data.title || !data.description || !data.area_id || !data.category_id)
    return responseJson({ error: "missing_required" }, 400);
  if (type === "event" && (!data.start || !data.venue))
    return responseJson({ error: "missing_event_fields" }, 400);
  if (type === "business" && (!data.address || !data.cap))
    return responseJson({ error: "missing_business_fields" }, 400);
  const [area, category] = await Promise.all([
    env.DB.prepare("SELECT id FROM la_areas WHERE id=? AND active=1")
      .bind(Number(data.area_id))
      .first(),
    env.DB.prepare("SELECT id,kind FROM la_categories WHERE id=? AND active=1")
      .bind(Number(data.category_id))
      .first(),
  ]);
  if (!area || !category)
    return responseJson({ error: "invalid_area_or_category" }, 400);
  if (type !== "report" && category.kind !== type)
    return responseJson({ error: "category_type_mismatch" }, 400);
  if (file && !validUpload(file))
    return responseJson({ error: "invalid_image" }, 400);
  const payload = { ...data };
  delete payload.email;
  delete payload.form_started_at;
  delete payload.rights_declared;
  let imageKey = null;
  if (file) {
    imageKey = `ugc/${Date.now()}-${randomToken(8)}.${file.type === "image/png" ? "png" : file.type === "image/webp" ? "webp" : "jpg"}`;
    const cleaned = stripImageMetadata(await file.arrayBuffer(), file.type);
    if (cleaned.byteLength > 1000000)
      return responseJson({ error: "image_too_large_after_cleaning" }, 400);
    const media = MediaStorageAdapter.fromEnv(env);
    if (!media.available())
      return responseJson({ error: "media_storage_unavailable" }, 503);
    await media.put(imageKey, cleaned, file.type);
    payload.image_key = imageKey;
  }
  const res = await env.DB.prepare(
    "INSERT INTO la_submissions(submission_type,email,email_hash,payload_json,status,expires_at) VALUES(?,?,?,?,'pending_email',datetime('now','+24 hours'))",
  )
    .bind(type, email, await sha256(email), JSON.stringify(payload))
    .run();
  const id = Number(res.meta.last_row_id);
  const row = await env.DB.prepare(
    "SELECT expires_at FROM la_submissions WHERE id=?",
  )
    .bind(id)
    .first();
  const verify = await hmacToken(
      env.TOKEN_SECRET,
      `verify:${id}:${row.expires_at}`,
    ),
    edit = await hmacToken(env.TOKEN_SECRET, `edit:${id}`);
  await env.DB.prepare(
    "UPDATE la_submissions SET verification_token_hash=?,edit_token_hash=? WHERE id=?",
  )
    .bind(await sha256(verify), await sha256(edit), id)
    .run();
  await enqueue(env.DB, "send_verify_email", id, {}, 50);
  return responseJson({ ok: true, id, status: "pending_email" }, 202);
}
async function sendVerifyEmail(env, id) {
  const s = await env.DB.prepare("SELECT * FROM la_submissions WHERE id=?")
    .bind(id)
    .first();
  if (!s || s.status !== "pending_email") return;
  const verify = await hmacToken(
      env.TOKEN_SECRET,
      `verify:${id}:${s.expires_at}`,
    ),
    edit = await hmacToken(env.TOKEN_SECRET, `edit:${id}`);
  const base = (env.PUBLIC_BASE_URL || "").replace(/\/$/, "");
  const link = `${base}/api/verify?id=${id}&token=${encodeURIComponent(verify)}`;
  const manage = `${base}/gestisci?id=${id}&token=${encodeURIComponent(edit)}`;
  await new BrevoEmail(env).send(
    s.email,
    "Verifica la tua segnalazione",
    `<p>Conferma il tuo indirizzo email:</p><p><a href="${html(link)}">Verifica email</a></p><p>Dopo la verifica potrai modificare o rimuovere il contenuto da <a href="${html(manage)}">questo link personale</a>.</p><p>Il link di verifica scade entro 24 ore.</p>`,
  );
}
async function verifySubmission(request, env) {
  const u = new URL(request.url),
    id = Number(u.searchParams.get("id") || 0),
    token = u.searchParams.get("token") || "";
  const s = await env.DB.prepare("SELECT * FROM la_submissions WHERE id=?")
    .bind(id)
    .first();
  if (
    !s ||
    s.status !== "pending_email" ||
    Date.parse(String(s.expires_at || "").replace(" ", "T") + "Z") <
      Date.now() ||
    (await sha256(token)) !== s.verification_token_hash
  )
    return responseJson({ error: "invalid_or_expired" }, 400);
  await env.DB.prepare(
    "UPDATE la_submissions SET status='verified',verified_at=CURRENT_TIMESTAMP,verification_token_hash=NULL WHERE id=?",
  )
    .bind(id)
    .run();
  await enqueue(env.DB, "submission_review", id, {}, 100);
  return new Response(
    "Email verificata. La segnalazione è stata presa in carico.",
    { headers: { "content-type": "text/plain;charset=utf-8" } },
  );
}
async function reviewSubmission(env, id) {
  const s = await env.DB.prepare("SELECT * FROM la_submissions WHERE id=?")
    .bind(id)
    .first();
  if (!s || !["verified", "held"].includes(s.status)) return;
  const p = safeJson(s.payload_json, {});
  const risk = ugcModerationGate({ title: p.title, text: p.description });
  await env.DB.prepare(
    "UPDATE la_submissions SET status='held',risk_score=? WHERE id=?",
  )
    .bind(risk.score, id)
    .run();
  await env.DB.prepare(
    "INSERT INTO la_moderation_actions(entity_type,entity_id,action,reason_code,automated) VALUES('submission',?,'held',?,1)",
  )
    .bind(id, risk.reason)
    .run();
}

async function publishApprovedSubmission(env, id) {
  const s = await env.DB.prepare("SELECT * FROM la_submissions WHERE id=?")
    .bind(id)
    .first();
  if (!s || !["verified", "held"].includes(s.status)) return;
  const p = safeJson(s.payload_json, {});
  const r = riskGate({ title: p.title, text: p.description }, 70);
  if (r.level !== "GREEN") {
    await env.DB.prepare(
      "UPDATE la_submissions SET status='held',risk_score=? WHERE id=?",
    )
      .bind(r.score, id)
      .run();
    return;
  }
  const type = s.submission_type === "report" ? "news" : s.submission_type;
  const title = String(p.title),
    summary = String(p.description).slice(0, 220),
    body = String(p.description);
  const ugcFp = await sha256(
    [
      type,
      Number(p.area_id),
      title.trim().toLowerCase(),
      type === "business"
        ? String(p.address || "")
            .trim()
            .toLowerCase()
        : String(p.start || "").slice(0, 16),
    ].join("|"),
  );
  let existing = s.content_id
    ? await env.DB.prepare("SELECT id FROM la_content WHERE id=?")
        .bind(s.content_id)
        .first()
    : null;
  if (!existing) {
    const dup = await env.DB.prepare(
      "SELECT id FROM la_content WHERE fingerprint=? AND status IN ('published','updated') LIMIT 1",
    )
      .bind(ugcFp)
      .first();
    if (dup) {
      await env.DB.prepare(
        "UPDATE la_submissions SET status='held',content_id=? WHERE id=?",
      )
        .bind(dup.id, id)
        .run();
      await log(env.DB, "info", "dedupe", "UGC duplicate held", {
        submission_id: id,
        content_id: dup.id,
      });
      return;
    }
  }
  if (existing) {
    await env.DB.prepare(
      "UPDATE la_content SET title=?,summary=?,body=?,area_id=?,category_id=?,updated_at=CURRENT_TIMESTAMP,status='updated',image_key=? WHERE id=?",
    )
      .bind(
        title,
        summary,
        body,
        Number(p.area_id),
        Number(p.category_id),
        p.image_key || null,
        existing.id,
      )
      .run();
    await env.DB.prepare(
      "UPDATE la_submissions SET status='approved' WHERE id=?",
    )
      .bind(id)
      .run();
    return;
  }
  const slugBase =
    title
      .toLowerCase()
      .normalize("NFKD")
      .replace(/[^a-z0-9]+/g, "-")
      .replace(/^-|-$/g, "")
      .slice(0, 80) || `contenuto-${id}`;
  const slug = `${slugBase}-${id}`;
  const res = await env.DB.prepare(
    "INSERT INTO la_content(content_type,slug,title,summary,body,area_id,category_id,fingerprint,status,published_at,updated_at,event_start,event_end,venue,address,price_type,external_url,organizer,phone,private_email,website,social,verified_email,image_key,auto_generated,risk_level) VALUES(?,?,?,?,?,?,?,?,'published',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,?,?,?,?,?,?,?,?,?,?,?,?,1,?,0,'GREEN')",
  )
    .bind(
      type,
      slug,
      title,
      summary,
      body,
      Number(p.area_id),
      Number(p.category_id),
      ugcFp,
      p.start || null,
      p.end || null,
      p.venue || null,
      p.address || null,
      p.price_type || null,
      p.url || null,
      p.organizer || null,
      p.phone || null,
      s.email,
      p.website || null,
      p.social || null,
      p.image_key || null,
    )
    .run();
  const contentId = Number(res.meta.last_row_id);
  await env.DB.prepare(
    "UPDATE la_submissions SET status='approved',content_id=? WHERE id=?",
  )
    .bind(contentId, id)
    .run();
}
async function reportContent(request, env) {
  if (!(await rateLimit(env.DB, request, "report", 10, 3600)))
    return responseJson({ error: "rate_limited" }, 429);
  const d = await request.json();
  if (
    !d.content_id ||
    ![
      "false_info",
      "privacy",
      "copyright",
      "offensive",
      "illegal",
      "spam",
      "other",
    ].includes(String(d.reason))
  )
    return responseJson({ error: "invalid_report" }, 400);
  const eh = d.email
    ? await sha256(String(d.email).trim().toLowerCase())
    : null;
  await env.DB.prepare(
    "INSERT INTO la_reports(content_id,reason,email_hash,details,status) VALUES(?,?,?,?,'open')",
  )
    .bind(
      Number(d.content_id),
      String(d.reason),
      eh,
      String(d.details || "").slice(0, 2000),
    )
    .run();
  return responseJson({ ok: true }, 202);
}

async function adOrder(request, env) {
  if (!(await rateLimit(env.DB, request, "ad_order", 3, 3600)))
    return responseJson({ error: "rate_limited" }, 429);
  const d = await request.json();
  if (d.website_hp) return responseJson({ ok: true }, 202);
  if (!validFormTiming(d.form_started_at))
    return responseJson({ error: "invalid_form_timing" }, 400);
  if (String(d.rights_declared || "") !== "1")
    return responseJson({ error: "rights_required" }, 400);
  if (String(d.terms_accepted || "") !== "1")
    return responseJson({ error: "terms_required" }, 400);
  const email = String(d.email || "")
    .trim()
    .toLowerCase();
  const businessName = String(d.business_name || "")
    .trim()
    .slice(0, 120);
  const title = String(d.title || "")
    .trim()
    .slice(0, 100);
  const body = String(d.body || "")
    .trim()
    .slice(0, 500);
  const targetUrl = String(d.target_url || "").trim();
  const areaId = Number(d.area_id || 0);
  const packages = { local_week: 490, local_month: 990, featured_month: 1990 };
  const packageCode = String(d.package_code || "");
  if (!businessName || !title || body.length < 20)
    return responseJson({ error: "missing_required" }, 400);
  if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email))
    return responseJson({ error: "invalid_email" }, 400);
  if (!packages[packageCode])
    return responseJson({ error: "invalid_package" }, 400);
  if (!safeSourceUrl(targetUrl))
    return responseJson({ error: "invalid_target_url" }, 400);
  if (new URL(targetUrl).protocol !== "https:")
    return responseJson({ error: "https_required" }, 400);
  const area = await env.DB.prepare(
    "SELECT id FROM la_areas WHERE id=? AND kind='neighborhood' AND active=1",
  )
    .bind(areaId)
    .first();
  if (!area) return responseJson({ error: "invalid_area" }, 400);
  const risk = ugcModerationGate({ title, text: `${businessName} ${body}` });
  const manageToken = randomToken(32);
  const result = await env.DB.prepare(
    "INSERT INTO la_ad_orders(business_name,email,email_hash,area_id,package_code,title,body,target_url,price_cents,manage_token_hash,risk_score,status,rights_declared,terms_accepted) VALUES(?,?,?,?,?,?,?,?,?,?,?,'held',1,1)",
  )
    .bind(
      businessName,
      email,
      await sha256(email),
      areaId,
      packageCode,
      title,
      body,
      targetUrl,
      packages[packageCode],
      await sha256(manageToken),
      risk.score,
    )
    .run();
  const id = Number(result.meta.last_row_id);
  await env.DB.prepare(
    "INSERT INTO la_moderation_actions(entity_type,entity_id,action,reason_code,automated) VALUES('ad_order',?,'held',?,1)",
  )
    .bind(id, risk.reason)
    .run();
  return responseJson(
    {
      ok: true,
      id,
      status: "held",
      price_cents: packages[packageCode],
      payment_mode: "test_no_payment",
      amount_charged_cents: 0,
      document: "Riepilogo ordine di prova",
      fiscal_document: false,
    },
    202,
  );
}
async function manageSubmission(request, env) {
  if (!(await rateLimit(env.DB, request, "manage", 10, 3600)))
    return responseJson({ error: "rate_limited" }, 429);
  const d = await request.json();
  const id = Number(d.id || 0),
    token = String(d.token || "");
  const s = await env.DB.prepare("SELECT * FROM la_submissions WHERE id=?")
    .bind(id)
    .first();
  if (!s || !s.edit_token_hash || (await sha256(token)) !== s.edit_token_hash)
    return responseJson({ error: "invalid_token" }, 403);
  if (d.action === "delete") {
    const payload = safeJson(s.payload_json, {});
    if (s.content_id)
      await env.DB.prepare(
        "UPDATE la_content SET status='expired',updated_at=CURRENT_TIMESTAMP WHERE id=?",
      )
        .bind(s.content_id)
        .run();
    if (payload.image_key) {
      try {
        await MediaStorageAdapter.fromEnv(env).delete(payload.image_key);
      } catch {}
    }
    await env.DB.prepare(
      "UPDATE la_submissions SET status='rejected',payload_json=? WHERE id=?",
    )
      .bind(JSON.stringify({ ...payload, image_key: null }), id)
      .run();
    return responseJson({ ok: true });
  }
  const p = safeJson(s.payload_json, {});
  const next = { ...p, ...d.payload };
  delete next.email;
  await env.DB.prepare(
    "UPDATE la_submissions SET payload_json=?,status='verified' WHERE id=?",
  )
    .bind(JSON.stringify(next), id)
    .run();
  await enqueue(env.DB, "submission_review", id, {}, 100);
  return responseJson({ ok: true, status: "review_queued" });
}

async function mediaGet(env, key) {
  if (!/^[a-zA-Z0-9_./-]{1,180}$/.test(key))
    return new Response("Not found", { status: 404 });
  const generated = key.match(/^generated\/area-(\d+)\.svg$/);
  if (generated) {
    const area = await env.DB.prepare(
      "SELECT name FROM la_areas WHERE id=? AND active=1",
    )
      .bind(Number(generated[1]))
      .first();
    if (!area) return new Response("Not found", { status: 404 });
    const xml = (s) =>
      String(s || "").replace(
        /[&<>"']/g,
        (c) =>
          ({
            "&": "&amp;",
            "<": "&lt;",
            ">": "&gt;",
            '"': "&quot;",
            "'": "&apos;",
          })[c],
      );
    const name = xml(area.name),
      svg = `<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="675" viewBox="0 0 1200 675" role="img" aria-labelledby="t d"><title id="t">Notizia locale: ${name}</title><desc id="d">Illustrazione originale AHÓ ROMA</desc><defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop stop-color="#073b4c"/><stop offset="1" stop-color="#0b5cff"/></linearGradient><pattern id="p" width="44" height="44" patternUnits="userSpaceOnUse"><path d="M0 44L44 0M22 66L66 22M-22 22L22-22" stroke="#fff" stroke-opacity=".07" stroke-width="8"/></pattern></defs><rect width="1200" height="675" rx="32" fill="url(#g)"/><rect width="1200" height="675" fill="url(#p)"/><circle cx="1030" cy="150" r="190" fill="#ffcc33" opacity=".92"/><path d="M0 555C205 465 330 590 530 515S870 430 1200 535V675H0Z" fill="#fff" opacity=".14"/><text x="76" y="130" fill="#ffcc33" font-family="Arial,sans-serif" font-size="30" font-weight="700" letter-spacing="5">AHÓ ROMA</text><text x="76" y="314" fill="#fff" font-family="Arial,sans-serif" font-size="70" font-weight="800">NOTIZIA LOCALE</text><text x="76" y="405" fill="#fff" font-family="Arial,sans-serif" font-size="54" font-weight="600">${name}</text><text x="76" y="602" fill="#fff" opacity=".85" font-family="Arial,sans-serif" font-size="25">Informazione verificata da fonte ufficiale</text></svg>`;
    return new Response(svg, {
      headers: {
        "content-type": "image/svg+xml;charset=utf-8",
        "cache-control": "public,max-age=31536000,immutable",
        "content-security-policy":
          "default-src 'none'; style-src 'none'; sandbox",
        "x-content-type-options": "nosniff",
      },
    });
  }
  const m = await MediaStorageAdapter.fromEnv(env).get(key);
  if (!m) return new Response("Not found", { status: 404 });
  return new Response(m.bytes, {
    headers: {
      "content-type": m.mime,
      "cache-control": "public,max-age=31536000,immutable",
      "x-content-type-options": "nosniff",
    },
  });
}

async function adminRoute(request, env, p) {
  if (!(await requireAdmin(request, env)))
    return responseJson({ error: "forbidden" }, 403);
  if (p === "/api/admin/health" && request.method === "GET") return health(env);
  if (p === "/api/admin/summary" && request.method === "GET") {
    const [content, areas, revenue] = await Promise.all([
      env.DB.prepare(
        "SELECT count(*) n FROM la_content WHERE status IN ('published','updated')",
      ).first(),
      env.DB.prepare(
        "SELECT count(*) n FROM la_areas WHERE active=1 AND kind='neighborhood'",
      ).first(),
      env.DB.prepare(
        "SELECT COALESCE(sum(amount_cents),0) n FROM la_revenue_events",
      )
        .first()
        .catch(() => ({ n: 0 })),
    ]);
    return responseJson({
      content: Number(content?.n || 0),
      areas: Number(areas?.n || 0),
      revenue_cents: Number(revenue?.n || 0),
    });
  }
  if (p === "/api/admin/ads" && request.method === "GET") {
    try {
      const q = await env.DB.prepare(
        "SELECT a.id,a.title,a.ad_type,a.active,COALESCE(sum(CASE WHEN e.event_type='click' THEN 1 ELSE 0 END),0) clicks FROM la_ads a LEFT JOIN la_ad_events e ON e.ad_id=a.id GROUP BY a.id ORDER BY a.priority,a.id",
      ).all();
      return responseJson(q.results || []);
    } catch {
      return responseJson([]);
    }
  }
  if (p === "/api/admin/queue" && request.method === "GET") {
    const q = await env.DB.prepare(
      "SELECT id,job_type,status,attempts,available_at,last_error FROM la_jobs ORDER BY id DESC LIMIT 100",
    ).all();
    return responseJson(q.results || []);
  }
  if (p === "/api/admin/queue/retry" && request.method === "POST") {
    const d = await request.json();
    await env.DB.prepare(
      "UPDATE la_jobs SET status='retry',available_at=CURRENT_TIMESTAMP,last_error=NULL WHERE id=? AND status='failed'",
    )
      .bind(Number(d.id))
      .run();
    return responseJson({ ok: true });
  }
  if (p === "/api/admin/source/test" && request.method === "POST") {
    const d = await request.json(),
      s = await env.DB.prepare("SELECT * FROM la_sources WHERE id=?")
        .bind(Number(d.id))
        .first();
    if (!s) return responseJson({ error: "not_found" }, 404);
    const items = await fetchSource(s);
    return responseJson({
      ok: true,
      items: items
        .slice(0, 3)
        .map((x) => ({ title: x.title, url: x.source_url })),
    });
  }
  if (p === "/api/admin/source/run" && request.method === "POST") {
    const d = await request.json(),
      s = await env.DB.prepare(
        "SELECT * FROM la_sources WHERE id=? AND active=1",
      )
        .bind(Number(d.id))
        .first();
    if (!s) return responseJson({ error: "not_found" }, 404);
    const items = await fetchSource(s);
    let queued = 0,
      held = 0;
    for (const item of items) {
      const id = await ingestItem(env.DB, s, item);
      if (id) {
        if (s.usage_policy === "discovery") {
          await env.DB.prepare(
            "UPDATE la_ingest SET status='held',updated_at=CURRENT_TIMESTAMP WHERE id=?",
          )
            .bind(id)
            .run();
          held++;
        } else {
          await enqueue(env.DB, "process_ingest", id, {}, 100);
          queued++;
        }
      }
    }
    await env.DB.prepare(
      "UPDATE la_sources SET last_checked_at=CURRENT_TIMESTAMP,last_success_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=?",
    )
      .bind(s.id)
      .run();
    return responseJson({ ok: true, items: items.length, queued, held });
  }
  if (p === "/api/admin/ingests/prepare" && request.method === "POST")
    return responseJson({
      ok: true,
      ...(await prepareDiscoveryReview(env.DB)),
    });
  if (p === "/api/admin/ingests" && request.method === "GET") {
    const status = new URL(request.url).searchParams.get("status") || "review";
    if (
      !["review", "new", "held", "rejected", "published", "updated"].includes(
        status,
      )
    )
      return responseJson({ error: "invalid_status" }, 400);
    const where =
      status === "review" ? "i.status IN ('new','held')" : "i.status=?";
    const stmt = env.DB.prepare(
      `SELECT i.id,i.status,i.original_title,substr(i.original_text,1,1200) original_text,i.original_date,i.source_url,i.created_at,s.name source_name,a.name area_name,k.name category_name FROM la_ingest i JOIN la_sources s ON s.id=i.source_id LEFT JOIN la_areas a ON a.id=i.detected_area LEFT JOIN la_categories k ON k.id=i.detected_category WHERE ${where} ORDER BY i.created_at DESC,i.id DESC LIMIT 100`,
    );
    const q =
      status === "review" ? await stmt.all() : await stmt.bind(status).all();
    return responseJson(q.results || []);
  }
  if (p === "/api/admin/ingest/probe" && request.method === "POST") {
    const d = await request.json(),
      row = await env.DB.prepare("SELECT source_url FROM la_ingest WHERE id=?")
        .bind(Number(d.id || 0))
        .first();
    if (!row) return responseJson({ error: "not_found" }, 404);
    const response = await fetch(row.source_url, {
      headers: { "user-agent": "LOCAL-AUTOPILOT/1.0" },
      redirect: "follow",
    });
    const text = await response.text();
    return responseJson({
      ok: response.ok,
      status: response.status,
      url: response.url,
      content_type: response.headers.get("content-type"),
      bytes: text.length,
      has_article: text.includes("data-search-data"),
    });
  }
  if (p === "/api/admin/ingest/preview" && request.method === "POST") {
    const d = await request.json(),
      id = Number(d.id || 0);
    const row = await env.DB.prepare(
      "SELECT i.*,s.name source_name,s.usage_policy,a.name area_name FROM la_ingest i JOIN la_sources s ON s.id=i.source_id LEFT JOIN la_areas a ON a.id=i.detected_area WHERE i.id=?",
    )
      .bind(id)
      .first();
    if (!row) return responseJson({ error: "not_found" }, 404);
    if (row.usage_policy !== "discovery")
      return responseJson({ error: "invalid_source_policy" }, 400);
    try {
      const detail = await fetchArticleDetails(row.source_url);
      return responseJson({
        id,
        row_status: row.status,
        source_name: row.source_name,
        source_url: row.source_url,
        area_name: row.area_name,
        title: detail.title,
        date: detail.date,
        description: detail.description,
        facts: reviewFactSheet(detail),
        requirements: {
          summary: "70-320 caratteri",
          body: "140-700 parole",
          original_text: true,
          verified_facts_only: true,
          image_license: "Illustrazione originale AHÓ ROMA · CC BY 4.0",
        },
      });
    } catch (e) {
      return responseJson(
        {
          error: "article_preview_failed",
          detail: String(e?.message || e).slice(0, 180),
        },
        502,
      );
    }
  }
  if (p === "/api/admin/ingest/review" && request.method === "POST") {
    const d = await request.json(),
      id = Number(d.id || 0),
      action = String(d.action || "");
    const row = await env.DB.prepare(
      "SELECT i.id,i.status,i.content_id,s.usage_policy FROM la_ingest i JOIN la_sources s ON s.id=i.source_id WHERE i.id=?",
    )
      .bind(id)
      .first();
    if (!row) return responseJson({ error: "not_found" }, 404);
    const rebuild = Boolean(
      d.rebuild &&
      row.content_id &&
      ["published", "updated"].includes(row.status),
    );
    if (!["new", "held"].includes(row.status) && !rebuild)
      return responseJson({ error: "not_reviewable" }, 409);
    if (action === "reject" && !rebuild) {
      await env.DB.prepare(
        "UPDATE la_ingest SET status='rejected',updated_at=CURRENT_TIMESTAMP WHERE id=?",
      )
        .bind(id)
        .run();
      return responseJson({ ok: true, status: "rejected" });
    }
    if (action !== "approve" || row.usage_policy !== "discovery")
      return responseJson({ error: "invalid_action" }, 400);
    const draft =
      d.draft && typeof d.draft === "object"
        ? {
            headline: String(d.draft.headline || ""),
            summary: String(d.draft.summary || ""),
            body: String(d.draft.body || ""),
            social_text: String(d.draft.social_text || d.draft.headline || ""),
          }
        : null;
    const source =
      d.source && typeof d.source === "object"
        ? {
            title: String(d.source.title || ""),
            date: d.source.date || null,
            text: String(d.source.text || ""),
            source_url: String(d.source.source_url || ""),
          }
        : null;
    try {
      const status = await processIngest(env, id, {
        editorApproved: true,
        editorDraft: draft,
        rebuild,
        verifiedSource: source,
      });
      return responseJson(
        { ok: status === "published" || status === "updated", status },
        status === "held" ? 409 : 200,
      );
    } catch (e) {
      const message = String(e?.message || e);
      if (
        message.startsWith("editor_draft_") ||
        message === "verified_source_invalid"
      )
        return responseJson({ error: message }, 400);
      throw e;
    }
  }
  if (p === "/api/admin/sources" && request.method === "GET") {
    const q = await env.DB.prepare(
      "SELECT id,name,url,parser_type,usage_policy,trust_level,interval_minutes,active,last_success_at FROM la_sources ORDER BY id DESC",
    ).all();
    return responseJson(q.results || []);
  }
  if (p === "/api/admin/sources" && request.method === "POST") {
    const d = await request.json();
    if (
      !d.name ||
      !safeSourceUrl(d.url) ||
      !["rss", "json", "html"].includes(d.parser_type) ||
      !["auto", "discovery", "blocked"].includes(d.usage_policy)
    )
      return responseJson({ error: "invalid_source" }, 400);
    const r = await env.DB.prepare(
      "INSERT INTO la_sources(name,url,source_type,parser_type,area_id,category_id,trust_level,usage_policy,interval_minutes,active,config_json) VALUES(?,?,?,?,?,?,?,?,?,?,?)",
    )
      .bind(
        String(d.name),
        String(d.url),
        String(d.source_type || "web"),
        d.parser_type,
        d.area_id ? Number(d.area_id) : null,
        d.category_id ? Number(d.category_id) : null,
        Math.max(0, Math.min(100, Number(d.trust_level || 50))),
        d.usage_policy,
        Math.max(15, Number(d.interval_minutes || 60)),
        d.active === false ? 0 : 1,
        d.config_json ? JSON.stringify(d.config_json) : null,
      )
      .run();
    return responseJson({ ok: true, id: Number(r.meta.last_row_id) }, 201);
  }
  if (p === "/api/admin/submissions" && request.method === "GET") {
    const q = await env.DB.prepare(
      "SELECT id,submission_type,json_extract(payload_json,'$.title') title,status,risk_score,content_id,created_at,verified_at FROM la_submissions ORDER BY id DESC LIMIT 100",
    ).all();
    return responseJson(q.results || []);
  }
  if (p === "/api/admin/submissions/review" && request.method === "POST") {
    const d = await request.json();
    const id = Number(d.id || 0),
      action = String(d.action || "");
    const row = await env.DB.prepare(
      "SELECT id,status FROM la_submissions WHERE id=?",
    )
      .bind(id)
      .first();
    if (!row) return responseJson({ error: "not_found" }, 404);
    if (row.status !== "held")
      return responseJson({ error: "not_reviewable" }, 409);
    if (action === "reject") {
      await env.DB.prepare(
        "UPDATE la_submissions SET status='rejected' WHERE id=?",
      )
        .bind(id)
        .run();
      await env.DB.prepare(
        "INSERT INTO la_moderation_actions(entity_type,entity_id,action,reason_code,notes) VALUES('submission',?,'rejected','editor_rejected',?)",
      )
        .bind(id, String(d.notes || "").slice(0, 500))
        .run();
      return responseJson({ ok: true, status: "rejected" });
    }
    if (action !== "approve")
      return responseJson({ error: "invalid_action" }, 400);
    await publishApprovedSubmission(env, id);
    const updated = await env.DB.prepare(
      "SELECT status,content_id FROM la_submissions WHERE id=?",
    )
      .bind(id)
      .first();
    if (updated.status !== "approved")
      return responseJson(
        { error: "risk_requires_manual_edit", status: updated.status },
        409,
      );
    await env.DB.prepare(
      "INSERT INTO la_moderation_actions(entity_type,entity_id,action,reason_code,notes) VALUES('submission',?,'approved','editor_approved',?)",
    )
      .bind(id, String(d.notes || "").slice(0, 500))
      .run();
    return responseJson({
      ok: true,
      status: updated.status,
      content_id: updated.content_id,
    });
  }
  if (p === "/api/admin/ad-orders" && request.method === "GET") {
    const q = await env.DB.prepare(
      "SELECT o.id,o.business_name,o.title,o.package_code,o.price_cents,o.status,o.risk_score,o.created_at,a.name area_name FROM la_ad_orders o LEFT JOIN la_areas a ON a.id=o.area_id ORDER BY o.id DESC LIMIT 100",
    ).all();
    return responseJson(q.results || []);
  }
  if (p === "/api/admin/ad-orders/review" && request.method === "POST") {
    const d = await request.json(),
      id = Number(d.id || 0),
      action = String(d.action || "");
    if (!["approve_test", "reject"].includes(action))
      return responseJson({ error: "invalid_action" }, 400);
    const status = action === "approve_test" ? "approved_test" : "rejected";
    const r = await env.DB.prepare(
      "UPDATE la_ad_orders SET status=?,reviewed_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status='held'",
    )
      .bind(status, id)
      .run();
    if (!Number(r.meta?.changes || 0))
      return responseJson({ error: "not_reviewable" }, 409);
    await env.DB.prepare(
      "INSERT INTO la_moderation_actions(entity_type,entity_id,action,reason_code,notes) VALUES('ad_order',?,?,?,?)",
    )
      .bind(
        id,
        action === "approve_test" ? "approved" : "rejected",
        action === "approve_test" ? "editor_approved_test" : "editor_rejected",
        String(d.notes || "").slice(0, 500),
      )
      .run();
    return responseJson({
      ok: true,
      status,
      payment_mode: "test_no_payment",
      amount_charged_cents: 0,
    });
  }
  if (p === "/api/admin/reports" && request.method === "GET") {
    const q = await env.DB.prepare(
      "SELECT r.id,r.content_id,r.reason,r.details,r.status,r.created_at,c.title FROM la_reports r LEFT JOIN la_content c ON c.id=r.content_id WHERE r.status IN ('open','held') ORDER BY r.id DESC LIMIT 100",
    ).all();
    return responseJson(q.results || []);
  }
  if (p === "/api/admin/reports/resolve" && request.method === "POST") {
    const d = await request.json(),
      id = Number(d.id || 0),
      action = String(d.action || "");
    const row = await env.DB.prepare(
      "SELECT content_id FROM la_reports WHERE id=? AND status IN ('open','held')",
    )
      .bind(id)
      .first();
    if (!row) return responseJson({ error: "not_found" }, 404);
    if (!["dismiss", "remove"].includes(action))
      return responseJson({ error: "invalid_action" }, 400);
    if (action === "remove")
      await env.DB.prepare(
        "UPDATE la_content SET status='expired',updated_at=CURRENT_TIMESTAMP WHERE id=?",
      )
        .bind(row.content_id)
        .run();
    await env.DB.prepare(
      "UPDATE la_reports SET status='resolved',resolved_at=CURRENT_TIMESTAMP WHERE id=?",
    )
      .bind(id)
      .run();
    await env.DB.prepare(
      "INSERT INTO la_moderation_actions(entity_type,entity_id,action,reason_code,notes) VALUES('report',?,?,?,?)",
    )
      .bind(
        id,
        action === "remove" ? "removed" : "rejected",
        action === "remove" ? "report_upheld" : "report_dismissed",
        String(d.notes || "").slice(0, 500),
      )
      .run();
    return responseJson({ ok: true, status: "resolved" });
  }
  if (p === "/api/admin/logs" && request.method === "GET") {
    const q = await env.DB.prepare(
      "SELECT id,level,component,message,created_at FROM la_logs ORDER BY id DESC LIMIT 100",
    ).all();
    return responseJson(q.results || []);
  }
  if (p === "/api/admin/territories" && request.method === "POST") {
    const d = await request.json();
    if (
      !d.name ||
      !d.slug ||
      !["city", "municipio", "neighborhood"].includes(d.kind)
    )
      return responseJson({ error: "invalid_area" }, 400);
    const r = await env.DB.prepare(
      "INSERT INTO la_areas(parent_id,name,slug,kind,cap_json,active) VALUES(?,?,?,?,?,1)",
    )
      .bind(
        d.parent_id ? Number(d.parent_id) : null,
        String(d.name),
        String(d.slug),
        d.kind,
        d.cap_json ? JSON.stringify(d.cap_json) : null,
      )
      .run();
    return responseJson({ ok: true, id: Number(r.meta.last_row_id) }, 201);
  }
  if (p === "/api/admin/categories" && request.method === "POST") {
    const d = await request.json();
    if (!d.name || !d.slug || !["news", "event", "business"].includes(d.kind))
      return responseJson({ error: "invalid_category" }, 400);
    const r = await env.DB.prepare(
      "INSERT INTO la_categories(name,slug,kind,active) VALUES(?,?,?,1)",
    )
      .bind(String(d.name), String(d.slug), d.kind)
      .run();
    return responseJson({ ok: true, id: Number(r.meta.last_row_id) }, 201);
  }
  return responseJson({ error: "not_found" }, 404);
}
async function health(env) {
  const [pending, failed, sources, heartbeat, deployed] = await Promise.all([
    env.DB.prepare(
      "SELECT count(*) n FROM la_jobs WHERE status IN ('pending','retry') AND available_at<datetime('now','-30 minutes')",
    ).first(),
    env.DB.prepare(
      "SELECT count(*) n FROM la_jobs WHERE status='failed'",
    ).first(),
    env.DB.prepare(
      "SELECT count(*) n FROM la_sources WHERE active=1 AND last_success_at IS NOT NULL AND last_success_at < datetime('now','-'||(interval_minutes*3)||' minutes')",
    ).first(),
    env.DB.prepare(
      "SELECT value FROM la_settings WHERE key='queue_heartbeat'",
    ).first(),
    env.DB.prepare(
      "SELECT value FROM la_settings WHERE key='deploy_started_at'",
    ).first(),
  ]);
  const started = deployed?.value
    ? Date.parse(String(deployed.value).replace(" ", "T") + "Z")
    : Date.now();
  const dead = heartbeat?.value
    ? Date.parse(String(heartbeat.value).replace(" ", "T") + "Z") <
      Date.now() - 45 * 60 * 1000
    : Date.now() - started > 60 * 60 * 1000;
  const action =
    Number(pending?.n || 0) +
    Number(failed?.n || 0) +
    Number(sources?.n || 0) +
    (dead ? 1 : 0);
  return responseJson({
    status: action ? "WARN" : "OK",
    action_required: action,
    queue_old: Number(pending?.n || 0),
    failed: Number(failed?.n || 0),
    sources_stale: Number(sources?.n || 0),
    cron_dead: dead,
    queue_heartbeat: heartbeat?.value || null,
  });
}
async function maintenance(env) {
  await env.DB.batch([
    env.DB.prepare(
      "DELETE FROM la_ingest WHERE created_at<datetime('now','-30 days') AND status IN ('published','updated','rejected')",
    ),
    env.DB.prepare(
      "DELETE FROM la_jobs WHERE status='completed' AND updated_at<datetime('now','-14 days')",
    ),
    env.DB.prepare(
      "DELETE FROM la_logs WHERE level='info' AND created_at<datetime('now','-14 days')",
    ),
    env.DB.prepare(
      "DELETE FROM la_logs WHERE level IN ('warning','error') AND created_at<datetime('now','-90 days')",
    ),
    env.DB.prepare(
      "UPDATE la_content SET status='expired',updated_at=CURRENT_TIMESTAMP WHERE content_type='event' AND event_end IS NOT NULL AND event_end<CURRENT_TIMESTAMP AND status IN ('published','updated')",
    ),
    env.DB.prepare(
      "DELETE FROM la_rate_limits WHERE expires_at<CURRENT_TIMESTAMP",
    ),
    env.DB.prepare(
      "UPDATE la_submissions SET verification_token_hash=NULL WHERE expires_at<CURRENT_TIMESTAMP AND status='pending_email'",
    ),
  ]);
  await env.DB.prepare(
    "INSERT INTO la_settings(key,value) VALUES('maintenance_heartbeat',CURRENT_TIMESTAMP) ON CONFLICT(key) DO UPDATE SET value=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP",
  ).run();
}
