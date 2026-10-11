import { BrevoEmail } from "../email/brevo.js";
import { html, randomToken, responseJson, sha256 } from "../core/utils.js";
import { rateLimit } from "./security.js";

const SESSION_COOKIE = "la_admin_session";
const SESSION_SECONDS = 30 * 24 * 60 * 60;
const CODE_SECONDS = 10 * 60;

export async function ensureAdminAuthTables(db) {
  await db.batch([
    db.prepare("CREATE TABLE IF NOT EXISTS la_admin_login_codes (id INTEGER PRIMARY KEY AUTOINCREMENT,code_salt TEXT NOT NULL,code_hash TEXT NOT NULL,attempts INTEGER NOT NULL DEFAULT 0,expires_at TEXT NOT NULL,consumed_at TEXT,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)"),
    db.prepare("CREATE INDEX IF NOT EXISTS idx_la_admin_codes_active ON la_admin_login_codes(expires_at,consumed_at,id)"),
    db.prepare("CREATE TABLE IF NOT EXISTS la_admin_sessions (session_hash TEXT PRIMARY KEY,expires_at TEXT NOT NULL,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,last_seen_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)"),
    db.prepare("CREATE INDEX IF NOT EXISTS idx_la_admin_sessions_expiry ON la_admin_sessions(expires_at)"),
  ]);
}

export async function adminAuthRoute(request, env, path) {
  await ensureAdminAuthTables(env.DB);
  if (path === "/api/admin-auth/status" && request.method === "GET") {
    return responseJson({ authenticated: await requireAdminSession(request, env) });
  }
  if (path === "/api/admin-auth/request" && request.method === "POST") {
    if (!sameOrigin(request)) return responseJson({ error: "invalid_origin" }, 403);
    if (!(await rateLimit(env.DB, request, "admin_login_request", 5, 3600)))
      return responseJson({ error: "too_many_requests" }, 429);
    const email = String(env.ADMIN_EMAIL || "otachecker@gmail.com").trim().toLowerCase();
    if (!env.BREVO_API_KEY)
      return responseJson({ error: "email_login_not_configured" }, 503);
    const code = randomSixDigitCode();
    const salt = randomToken(16);
    const hash = await deriveCodeHash(code, salt);
    const expiry = sqliteTimestamp(Date.now() + CODE_SECONDS * 1000);
    const inserted = await env.DB.prepare(
      "INSERT INTO la_admin_login_codes(code_salt,code_hash,expires_at) VALUES(?,?,?)",
    ).bind(salt, hash, expiry).run();
    try {
      await new BrevoEmail(env).send(
        email,
        "Codice di accesso AHÓ ROMA",
        `<p>Il tuo codice monouso per il back office è:</p><p style="font-size:32px;font-weight:800;letter-spacing:6px">${html(code)}</p><p>Scade tra 10 minuti. Se non hai richiesto tu l'accesso, ignora questa email.</p>`,
      );
    } catch {
      await env.DB.prepare("DELETE FROM la_admin_login_codes WHERE id=?")
        .bind(Number(inserted.meta.last_row_id)).run();
      return responseJson({ error: "email_delivery_unavailable" }, 503);
    }
    return responseJson({ ok: true, expires_in_seconds: CODE_SECONDS });
  }
  if (path === "/api/admin-auth/verify" && request.method === "POST") {
    if (!sameOrigin(request)) return responseJson({ error: "invalid_origin" }, 403);
    if (!(await rateLimit(env.DB, request, "admin_login_verify", 20, 3600)))
      return responseJson({ error: "too_many_requests" }, 429);
    const body = await request.json().catch(() => ({}));
    const code = String(body.code || "").trim();
    if (!/^\d{6}$/.test(code)) return responseJson({ error: "invalid_code" }, 400);
    const row = await env.DB.prepare(
      "SELECT id,code_salt,code_hash,attempts FROM la_admin_login_codes WHERE consumed_at IS NULL AND expires_at>CURRENT_TIMESTAMP ORDER BY id DESC LIMIT 1",
    ).first();
    if (!row || Number(row.attempts || 0) >= 5)
      return responseJson({ error: "invalid_or_expired_code" }, 400);
    const derived = await deriveCodeHash(code, row.code_salt);
    await env.DB.prepare("UPDATE la_admin_login_codes SET attempts=attempts+1 WHERE id=? AND consumed_at IS NULL")
      .bind(row.id).run();
    if (!constantTimeEqual(derived, row.code_hash))
      return responseJson({ error: "invalid_or_expired_code" }, 400);
    const session = randomToken(32);
    const sessionHash = await sha256(session);
    const expiry = sqliteTimestamp(Date.now() + SESSION_SECONDS * 1000);
    const consumed = await env.DB.prepare(
      "UPDATE la_admin_login_codes SET consumed_at=CURRENT_TIMESTAMP WHERE id=? AND consumed_at IS NULL AND attempts<=5",
    ).bind(row.id).run();
    if (Number(consumed.meta?.changes || 0) !== 1)
      return responseJson({ error: "invalid_or_expired_code" }, 400);
    await env.DB.batch([
      env.DB.prepare("INSERT INTO la_admin_sessions(session_hash,expires_at) VALUES(?,?)").bind(sessionHash, expiry),
      env.DB.prepare("DELETE FROM la_admin_sessions WHERE expires_at<=CURRENT_TIMESTAMP"),
    ]);
    return responseJson({ ok: true, expires_in_days: 30 }, 200, { "set-cookie": sessionCookie(session) });
  }
  if (path === "/api/admin-auth/logout" && request.method === "POST") {
    if (!sameOrigin(request)) return responseJson({ error: "invalid_origin" }, 403);
    const raw = readCookie(request, SESSION_COOKIE);
    if (raw) await env.DB.prepare("DELETE FROM la_admin_sessions WHERE session_hash=?")
      .bind(await sha256(raw)).run();
    return responseJson({ ok: true }, 200, { "set-cookie": clearSessionCookie() });
  }
  return responseJson({ error: "not_found" }, 404);
}

export async function requireAdminSession(request, env) {
  if (!["GET", "HEAD", "OPTIONS"].includes(request.method) && !sameOrigin(request)) return false;
  const raw = readCookie(request, SESSION_COOKIE);
  if (!raw || !/^[a-f0-9]{64}$/.test(raw)) return false;
  const row = await env.DB.prepare(
    "SELECT session_hash FROM la_admin_sessions WHERE session_hash=? AND expires_at>CURRENT_TIMESTAMP",
  ).bind(await sha256(raw)).first();
  return Boolean(row);
}

export async function deriveCodeHash(code, salt) {
  const material = await crypto.subtle.importKey("raw", new TextEncoder().encode(String(code)), "PBKDF2", false, ["deriveBits"]);
  const bits = await crypto.subtle.deriveBits(
    { name: "PBKDF2", hash: "SHA-256", salt: new TextEncoder().encode(String(salt)), iterations: 100000 },
    material,
    256,
  );
  return [...new Uint8Array(bits)].map((b) => b.toString(16).padStart(2, "0")).join("");
}

export function readCookie(request, name) {
  const cookies = String(request.headers.get("cookie") || "").split(";");
  for (const part of cookies) {
    const at = part.indexOf("=");
    if (at > 0 && part.slice(0, at).trim() === name)
      return decodeURIComponent(part.slice(at + 1).trim());
  }
  return "";
}

export function sessionCookie(value) {
  return `${SESSION_COOKIE}=${value}; Max-Age=${SESSION_SECONDS}; Path=/; HttpOnly; Secure; SameSite=Strict`;
}

export function clearSessionCookie() {
  return `${SESSION_COOKIE}=; Max-Age=0; Path=/; HttpOnly; Secure; SameSite=Strict`;
}

function sameOrigin(request) {
  const origin = request.headers.get("origin");
  return Boolean(origin && origin === new URL(request.url).origin);
}

function randomSixDigitCode() {
  const n = crypto.getRandomValues(new Uint32Array(1))[0] % 1000000;
  return String(n).padStart(6, "0");
}

function sqliteTimestamp(ms) {
  return new Date(ms).toISOString().slice(0, 19).replace("T", " ");
}

function constantTimeEqual(a, b) {
  const left = String(a), right = String(b);
  let diff = left.length ^ right.length;
  const length = Math.max(left.length, right.length);
  for (let i = 0; i < length; i++) diff |= (left.charCodeAt(i) || 0) ^ (right.charCodeAt(i) || 0);
  return diff === 0;
}
