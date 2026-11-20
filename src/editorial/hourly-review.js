import { riskGate, valueGate } from "../core/gates.js";
import { fetchArticleDetails, reviewFactSheet } from "../core/article.js";

// This is an editorial selection gate, NOT a publishing permit.
// News and citizen reports remain on hold until a sourced, original draft is approved.
const PRIMARY_HOSTS = new Set(["www.comune.roma.it", "comune.roma.it", "www.atac.roma.it", "atac.roma.it", "www.rfi.it", "rfi.it"]);
const MAX_SOURCE_AGE_HOURS = 72;
const TARGET_PER_DAY = 20;
const MAX_REVIEW_CANDIDATES = 20;

export function editorialHourKey(now = new Date()) {
  return new Date(now).toISOString().slice(0, 13);
}

export function romeDayStartUtc(now = new Date()) {
  const dt = new Date(now);
  const parts = new Intl.DateTimeFormat("en-GB", {
    timeZone: "Europe/Rome", day: "2-digit", month: "2-digit", year: "numeric"
  }).formatToParts(dt);
  const values = Object.fromEntries(parts.filter(p => p.type !== "literal").map(p => [p.type, p.value]));
  const midnightUtc = Date.UTC(Number(values.year), Number(values.month) - 1, Number(values.day));
  const zone = new Intl.DateTimeFormat("en-US", { timeZone: "Europe/Rome", timeZoneName: "shortOffset" })
    .formatToParts(new Date(midnightUtc)).find(p => p.type === "timeZoneName")?.value || "GMT+1";
  const match = zone.match(/GMT([+-])(\d{1,2})(?::(\d{1,2}))?/);
  const offset = match ? (match[1] === "+" ? 1 : -1) * (Number(match[2]) * 60 + Number(match[3] || 0)) : 60;
  return new Date(midnightUtc - offset * 60000).toISOString().slice(0, 19).replace("T", " ");
}

export function classifyHourlyCandidate(row, now = new Date()) {
  const date = Date.parse(String(row.original_date || ""));
  if (!Number.isFinite(date) || date > now.valueOf() + 3600000 ||
      date < now.valueOf() - MAX_SOURCE_AGE_HOURS * 3600000)
    return "stale_or_invalid_date";
  if (row.content_id || row.already_published) return "already_published";
  const text = String(row.original_text || "").trim();
  const title = String(row.original_title || "").trim();
  if (!Number(row.detected_area) || title.length < 12 || text.length < 100)
    return "insufficient_local_facts";
  if (!String(row.source_url || "").startsWith("https://")) return "invalid_source_url";
  let host = "";
  try { host = new URL(row.source_url).hostname.toLowerCase(); } catch { return "invalid_source_url"; }
  if (!["official", "official_bridge"].includes(String(row.source_type)) ||
      Number(row.trust_level) < 90 || !PRIMARY_HOSTS.has(host))
    return "secondary_source_needs_primary_verification";
  if (riskGate({ title, text }, Number(row.trust_level)).level !== "GREEN")
    return "sensitive_needs_editor";
  if (!valueGate({ title, text, area_id: row.detected_area, original_date: row.original_date }).pass)
    return "low_local_utility";
  // The Municipio I feed can carry citywide material; AHÓ covers only Trastevere/Porta Portese there.
  if (Number(row.detected_area) === 5 && !/trastevere|porta portese/i.test(title + " " + text))
    return "outside_editorial_scope";
  return "source_candidate_needs_original_draft";
}


/**
 * A short official feed excerpt is NOT a publishable article. However, a
 * fresh primary-source lead can be flagged for full-source verification
 * rather than buried among stale/unsafe records. Never grants approval.
 */
export function needsFullPrimarySource(row, now = new Date()) {
  if (row.content_id || row.already_published) return false;
  const date = Date.parse(String(row.original_date || ""));
  if (!Number.isFinite(date) || date < now.valueOf() - MAX_SOURCE_AGE_HOURS * 3600000 ||
      date > now.valueOf() + 3600000) return false;
  const title = String(row.original_title || "").trim();
  const excerpt = String(row.original_text || "").trim();
  if (!Number(row.detected_area) || title.length < 12) return false;
  if (!["official", "official_bridge"].includes(String(row.source_type)) ||
      Number(row.trust_level) < 90) return false;
  let url;
  try { url = new URL(String(row.source_url || "")); } catch { return false; }
  if (url.protocol !== "https:" || !PRIMARY_HOSTS.has(url.hostname.toLowerCase())) return false;
  if (riskGate({title, text:excerpt}, Number(row.trust_level)).level !== "GREEN") return false;
  if (Number(row.detected_area) === 5 && !/trastevere|porta portese/i.test(title + " " + excerpt))
    return false;
  return true;
}


/**
 * Use the existing hourly editorial cron to enrich up to two fresh, trusted
 * source leads. Never generates an article or changes a moderation decision.
 */
export async function enrichOfficialBriefs(candidates, fetcher = fetchArticleDetails) {
  for (const item of candidates.filter(c =>
    ["awaiting_editorial_verification","fetch_full_primary_source_before_review"].includes(c.decision)
  ).slice(0, 2)) {
    let hostname="";
    try {const u=new URL(item.source_url);if(u.protocol==="https:")hostname=u.hostname.toLowerCase();}
    catch {item.evidence_status="invalid_url";continue;}
    if (!PRIMARY_HOSTS.has(hostname)) {item.evidence_status="requires_primary_source";continue;}
    try {
      const detail=await fetcher(item.source_url);
      const title=String(detail.title||"").trim(),text=String(detail.text||"").trim();
      if(title.length<12||text.length<300||riskGate({title,text},95).level!=="GREEN"){
        item.evidence_status="insufficient_or_sensitive_full_source";continue;
      }
      item.evidence_status="source_retrieved_original_draft_required";
      item.verified_source_title=title.slice(0,180);
      item.verified_source_date=detail.date||null;
      item.source_fact_brief=reviewFactSheet(detail).slice(0,4).map(x=>String(x.text||"").slice(0,220));
      item.full_source_characters=text.length;
    }catch{item.evidence_status="primary_source_unavailable";}
  }
  return candidates;
}

export async function hourlyEditorialReview(db, now = new Date()) {
  const hour = editorialHourKey(now);
  // The existing 15-minute cron/foreground tick can overlap; one conditional DB
  // write owns each hourly window without extra jobs, accounts or services.
  const lock = await db.prepare(
    "INSERT INTO la_settings(key,value,updated_at) VALUES('aho_editorial_hour_v1',?,CURRENT_TIMESTAMP) " +
    "ON CONFLICT(key) DO UPDATE SET value=excluded.value,updated_at=CURRENT_TIMESTAMP " +
    "WHERE la_settings.value<>excluded.value"
  ).bind(hour).run();
  if (Number(lock.meta?.changes || 0) !== 1) return { status: "already_checked", hour };

  const freshCutoff = new Date(now.valueOf() - MAX_SOURCE_AGE_HOURS * 3600000).toISOString();
  const [rows, published] = await Promise.all([
    db.prepare(
      "SELECT i.id,i.original_title,i.original_text,i.original_date,i.source_url,i.content_id," +
      "i.detected_area,s.source_type,s.trust_level,s.name AS source_name," +
      "EXISTS(SELECT 1 FROM la_content c WHERE c.source_url=i.source_url " +
      "OR (c.content_type='news' AND lower(c.title)=lower(i.original_title))) AS already_published " +
      "FROM la_ingest i JOIN la_sources s ON s.id=i.source_id " +
      "WHERE i.status='held' AND s.usage_policy='discovery' AND i.original_date>=? " +
      "ORDER BY i.original_date DESC,i.id DESC LIMIT 160"
    ).bind(freshCutoff).all(),
    db.prepare(
      "SELECT count(*) AS n FROM la_content WHERE content_type='news' " +
      "AND status IN ('published','updated') AND published_at>=?"
    ).bind(romeDayStartUtc(now)).first()
  ]);

  const counts = {};
  const shortlist = [];
  for (const row of rows.results || []) {
    const decision = classifyHourlyCandidate(row, now);
    counts[decision] = (counts[decision] || 0) + 1;
    if (shortlist.length < MAX_REVIEW_CANDIDATES &&
       (decision === "source_candidate_needs_original_draft" ||
       (["insufficient_local_facts","low_local_utility"].includes(decision) &&
        needsFullPrimarySource(row, now)))) {
      shortlist.push({ ingest_id: Number(row.id), source_url: row.source_url,
        title: row.original_title, source: row.source_name,
        date: row.original_date, decision: decision === "source_candidate_needs_original_draft" ?
          "awaiting_editorial_verification" : "fetch_full_primary_source_before_review" });
    }
  }
  await enrichOfficialBriefs(shortlist);
  const publishedToday = Number(published?.n || 0);
  const digest = {
    hour_utc: hour, day_start_utc: romeDayStartUtc(now),
    target_daily: TARGET_PER_DAY,
    published_today_rome: publishedToday,
    remaining_to_target: Math.max(0, TARGET_PER_DAY - publishedToday),
    candidate_count: shortlist.length,
    primary_source_expansions: shortlist.filter(x => x.decision ===
      "fetch_full_primary_source_before_review").length,
    status: "review_only_no_unsupervised_publication",
    candidates: shortlist, decisions: counts
  };
  await db.prepare(
    "INSERT INTO la_settings(key,value,updated_at) VALUES('aho_editorial_digest_v1',?,CURRENT_TIMESTAMP) " +
    "ON CONFLICT(key) DO UPDATE SET value=excluded.value,updated_at=CURRENT_TIMESTAMP"
  ).bind(JSON.stringify(digest)).run();
  return digest;
}
