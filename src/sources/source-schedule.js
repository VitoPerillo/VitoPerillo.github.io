// Bounded source polling for the existing 15-minute cron. Collection only.
export const POLL_LIMIT = 5;
export const WINDOW_MS = 15 * 60 * 1000;
const DUE_AT = `CASE WHEN datetime(last_checked_at) IS NULL THEN '1970-01-01 00:00:00'
 ELSE datetime(last_checked_at, '+' || CASE
 WHEN datetime(last_success_at) IS NULL OR datetime(last_success_at) < datetime(last_checked_at)
 THEN min(10080,max(30,2*max(1,interval_minutes))) ELSE max(1,interval_minutes) END || ' minutes') END`;
const FEED = `CASE WHEN parser_type IN ('rss','json') OR
 (source_type='official' AND url LIKE 'https://www.comune.roma.it/web/it/notizie.page?mun=%') OR
 (CASE WHEN json_valid(config_json) THEN json_extract(config_json,'$.poll_kind') ELSE NULL END)='feed'
 THEN 1 ELSE 0 END`;
const ELIGIBLE = "active=1 AND usage_policy!='blocked'";
export async function claimSourceWindow(db, now = new Date()) {
  const bucket = String(Math.floor(now.getTime() / WINDOW_MS));
  const r = await db.prepare(`INSERT INTO la_settings(key,value,updated_at)
    VALUES('aho_source_poll_window_v1',?,CURRENT_TIMESTAMP)
    ON CONFLICT(key) DO UPDATE SET value=excluded.value,updated_at=CURRENT_TIMESTAMP
    WHERE CAST(la_settings.value AS INTEGER)<CAST(excluded.value AS INTEGER)`).bind(bucket).run();
  return Number(r.meta?.changes || 0) === 1;
}
export async function selectDueSources(db, now = new Date()) {
  const r = await db.prepare(`WITH due AS (
    SELECT *, ${DUE_AT} AS due_at, ${FEED} AS is_feed FROM la_sources WHERE ${ELIGIBLE}
  ), ranked AS (
    SELECT *, row_number() OVER(PARTITION BY is_feed ORDER BY due_at,id) AS rn
    FROM due WHERE due_at <= datetime(?)
  ) SELECT * FROM ranked ORDER BY CASE
    WHEN is_feed=1 AND rn<=4 THEN 0 WHEN is_feed=0 AND rn=1 THEN 1 ELSE 2 END, due_at,id LIMIT 5`)
    .bind(now.toISOString()).all();
  return r.results || [];
}
export async function claimDueSource(db, id, now = new Date()) {
  const stamp = now.toISOString();
  const r = await db.prepare(`UPDATE la_sources SET last_checked_at=? WHERE id=? AND ${ELIGIBLE}
    AND (${DUE_AT})<=datetime(?)`).bind(stamp,id,stamp).run();
  return Number(r.meta?.changes || 0) === 1;
}
