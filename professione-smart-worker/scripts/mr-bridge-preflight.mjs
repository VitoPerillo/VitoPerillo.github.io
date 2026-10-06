import fs from "node:fs";
import path from "node:path";

const ROOT = process.cwd();
const routePath = path.join(ROOT, "mr-route.json");

function block(code, detail = "") {
  console.error(`MR_BRIDGE_BLOCK=${code}`);
  if (detail) console.error(`MR_BRIDGE_DETAIL=${String(detail).slice(0, 500)}`);
  process.exit(20);
}

function readJson(file) {
  try {
    return JSON.parse(fs.readFileSync(file, "utf8"));
  } catch (error) {
    block("route_manifest_invalid", error?.message || error);
  }
}

function normalizeCloudflareCredential(raw) {
  let value = String(raw || "").trim();
  if (!value) return "";

  if (value.startsWith("{") && value.endsWith("}")) {
    try {
      const parsed = JSON.parse(value);
      const candidates = [
        parsed.CLOUDFLARE_API_TOKEN,
        parsed.cloudflare_api_token,
        parsed.api_token,
        parsed.token,
        parsed.value,
      ];
      const found = candidates.find((v) => typeof v === "string" && v.trim());
      if (found) value = found.trim();
    } catch {
      // Keep the original value and let the explicit format checks below decide.
    }
  }

  value = value
    .replace(/^Authorization\s*:\s*Bearer\s+/i, "")
    .replace(/^Bearer\s+/i, "")
    .replace(/^CLOUDFLARE_API_TOKEN\s*=\s*/i, "")
    .trim();

  if ((value.startsWith('"') && value.endsWith('"')) || (value.startsWith("'") && value.endsWith("'"))) {
    value = value.slice(1, -1).trim();
  }
  return value;
}

function exportMaskedToken(token) {
  console.log(`::add-mask::${token}`);
  const envFile = process.env.GITHUB_ENV;
  if (!envFile) return;
  const delimiter = "MRBRIDGE_CF_TOKEN_EOF";
  fs.appendFileSync(envFile, `CF_API_TOKEN<<${delimiter}\n${token}\n${delimiter}\n`);
}

function scanForCommittedSecrets(files) {
  const checks = [
    { label: "stripe_live_secret", re: /\b(?:sk|rk)_live_[A-Za-z0-9_\-]{16,}\b/g },
    { label: "cloudflare_user_token", re: /\bcfut_[A-Za-z0-9_\-]{20,}\b/g },
    { label: "private_key", re: /-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/g },
  ];

  for (const relative of files) {
    const full = path.join(ROOT, relative);
    if (!fs.existsSync(full)) block("required_source_missing", relative);
    const text = fs.readFileSync(full, "utf8");
    for (const check of checks) {
      check.re.lastIndex = 0;
      if (check.re.test(text)) block("committed_secret_detected", `${check.label}:${relative}`);
    }
  }
}

async function cfGet(token, endpoint) {
  const response = await fetch(`https://api.cloudflare.com/client/v4${endpoint}`, {
    headers: {
      Authorization: `Bearer ${token}`,
      "Content-Type": "application/json",
      "User-Agent": "MR-Bridge-Professione-Smart/1",
    },
  });
  const data = await response.json().catch(() => ({}));
  if (!response.ok || data.success === false) {
    const first = Array.isArray(data.errors) ? data.errors[0] : null;
    const code = first?.code ?? response.status;
    const message = first?.message ?? `HTTP ${response.status}`;
    block("cloudflare_api_rejected", `${endpoint}:${code}:${message}`);
  }
  return data.result;
}

const route = readJson(routePath);
if (route.control_plane !== "MR Bridge") block("control_plane_not_mr_bridge");
if (route.source?.repository !== "VitoPerillo/VitoPerillo.github.io") block("unexpected_repository");
if (route.source?.branch !== "professione-smart-staging") block("unexpected_staging_branch");
if (route.runtime?.provider !== "cloudflare" || route.runtime?.worker !== "professione-smart") block("unexpected_runtime");
if (route.fallbacks?.includes("tinyfish")) block("tinyfish_not_allowed_in_normal_path");
if (route.break_glass?.desktop_commander !== true) block("break_glass_policy_missing");

const ref = process.env.GITHUB_REF_NAME || "";
if (ref && ref !== route.source.branch) block("wrong_branch", ref);

scanForCommittedSecrets([
  "src/index.js",
  "src/provider-photo.js",
  "wrangler.toml",
  "package.json",
  "CANONICAL_PIPELINE.md",
  "mr-route.json",
]);

const rawToken = process.env.CF_API_TOKEN_RAW || process.env.CF_API_TOKEN || "";
const token = normalizeCloudflareCredential(rawToken);
if (!token) block("cloudflare_authorization_missing");
exportMaskedToken(token);

const accountId = process.env.CF_ACCOUNT_ID || route.runtime.account_id || "";
if (!/^[a-f0-9]{32}$/i.test(accountId)) block("cloudflare_account_id_invalid");

const verify = await cfGet(token, "/user/tokens/verify");
if (verify?.status !== "active") block("cloudflare_token_not_active", verify?.status || "unknown");

const scripts = await cfGet(token, `/accounts/${accountId}/workers/scripts`);
const worker = Array.isArray(scripts) ? scripts.find((item) => item?.id === route.runtime.worker) : null;
if (!worker?.tag) block("cloudflare_worker_not_visible", route.runtime.worker);

const buildTokens = await cfGet(token, `/accounts/${accountId}/builds/tokens`);
if (!Array.isArray(buildTokens) || !buildTokens[0]?.build_token_uuid) block("cloudflare_build_token_unavailable");

await cfGet(token, `/accounts/${accountId}/builds/workers/${worker.tag}/triggers`);

console.log(`MR_BRIDGE_GATE=GREEN`);
console.log(`MR_PROJECT=${route.project}`);
console.log(`MR_SOURCE=${route.source.repository}@${route.source.branch}`);
console.log(`MR_RUNTIME=${route.runtime.provider}:${route.runtime.worker}`);
console.log(`MR_PC_DEPENDENCY=break-glass-only`);
console.log(`MR_TINYFISH_DEPENDENCY=none`);
