const PLANS = Object.freeze({
  fenice: { monthly: 0, annual: null, questions: 1, trialDays: 30 },
  pegaso: { monthly: 6.90, annual: 69, questions: 1 },
  orione: { monthly: 9.90, annual: 99, questions: 4 },
  andromeda: { monthly: 14.90, annual: 149, questions: 12 }
});

function json(data, status = 200, extra = {}) {
  return new Response(JSON.stringify(data), {
    status,
    headers: {
      "content-type": "application/json; charset=utf-8",
      "cache-control": "no-store",
      ...extra
    }
  });
}

function cors(env) {
  return {
    "access-control-allow-origin": env.PUBLIC_ORIGIN || "https://vitoperillo.github.io",
    "access-control-allow-methods": "GET,POST,OPTIONS",
    "access-control-allow-headers": "content-type,authorization,x-csrf-token"
  };
}

export default {
  async fetch(request, env) {
    const url = new URL(request.url);
    if (request.method === "OPTIONS") return new Response(null, { status: 204, headers: cors(env) });

    if (url.pathname === "/health") {
      let db = false;
      try { await env.DB.prepare("SELECT 1 AS ok").first(); db = true; } catch (_) {}
      return json({
        ok: db,
        service: "ritratto-stellare-api",
        paypal_environment: env.PAYPAL_ENV || "sandbox",
        live_authorized: env.LIVE_COMMERCIAL_AUTHORIZED === "true",
        db
      }, db ? 200 : 503, cors(env));
    }

    if (url.pathname === "/v1/plans" && request.method === "GET") {
      return json({ plans: PLANS }, 200, cors(env));
    }

    if (url.pathname.startsWith("/v1/paypal/") && env.PAYPAL_ENV === "live" && env.LIVE_COMMERCIAL_AUTHORIZED !== "true") {
      return json({ ok:false, error:"live_commercial_not_authorized" }, 503, cors(env));
    }

    return json({ ok:false, error:"not_found" }, 404, cors(env));
  }
};
