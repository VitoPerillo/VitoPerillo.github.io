const DEFAULT_ORIGIN = "https://www.yoganostress.it/staging-gestionale";
const ORIGIN_PREFIX = "/staging-gestionale";

export default {
  async fetch(request, env) {
    const url = new URL(request.url);
    const p = url.pathname;

    if (p === "/" || p === "/index.html" || p === "/bridge.js") {
      return env.ASSETS.fetch(request);
    }

    if (!isAllowedProxyPath(p)) {
      return new Response("Not found", { status: 404 });
    }

    return proxyToRitratto(request, env);
  },
};

function isAllowedProxyPath(path) {
  if (path.startsWith(ORIGIN_PREFIX + "/")) return true;
  return (
    path.startsWith("/ritratto-stellare/") ||
    path === "/ritratto-stellare" ||
    path.startsWith("/il-mio-cielo/") ||
    path === "/il-mio-cielo" ||
    path.startsWith("/ritratto-stellare-privacy/") ||
    path.startsWith("/ritratto-stellare-termini/") ||
    path.startsWith("/wp-json/ritratto-stellare/") ||
    path.startsWith("/wp-content/plugins/ritratto-stellare/") ||
    path.startsWith("/wp-content/themes/") ||
    path.startsWith("/wp-includes/")
  );
}

async function proxyToRitratto(request, env) {
  const incoming = new URL(request.url);
  const originBase = (env.ORIGIN_BASE || DEFAULT_ORIGIN).replace(/\/$/, "");
  const publicBase = incoming.origin;

  let path = incoming.pathname;
  if (path.startsWith(ORIGIN_PREFIX + "/")) {
    path = path.slice(ORIGIN_PREFIX.length);
  }

  const target = new URL(originBase + path);
  target.search = incoming.search;

  const reqHeaders = new Headers(request.headers);
  reqHeaders.delete("host");
  reqHeaders.set("origin", originBase);
  reqHeaders.set("referer", originBase + "/ritratto-stellare/");

  const upstream = await fetch(target.toString(), {
    method: request.method,
    headers: reqHeaders,
    body: ["GET", "HEAD"].includes(request.method) ? undefined : request.body,
    redirect: "manual",
  });

  const headers = new Headers(upstream.headers);
  rewriteCookies(upstream.headers, headers);
  rewriteLocation(headers, originBase, publicBase);

  const ct = headers.get("content-type") || "";
  const shouldRewrite =
    ct.includes("text/html") ||
    ct.includes("application/json") ||
    ct.includes("javascript") ||
    ct.includes("text/css");

  if (!shouldRewrite || request.method === "HEAD" || upstream.status === 204) {
    return new Response(upstream.body, {
      status: upstream.status,
      statusText: upstream.statusText,
      headers,
    });
  }

  let text = await upstream.text();
  text = rewriteBody(text, originBase, publicBase);

  if (ct.includes("text/html")) {
    const injection =
      '<style>.wp-site-blocks>header,.wp-site-blocks>footer{display:none!important}</style>' +
      '<script src="/bridge.js?v=20260925-1"></script>';
    text = text.includes("</body>")
      ? text.replace("</body>", injection + "</body>")
      : text + injection;
    headers.set("cache-control", "no-store");
  }
  if (ct.includes("application/json")) headers.set("cache-control", "no-store");

  headers.delete("content-length");
  headers.delete("content-encoding");
  headers.delete("etag");

  return new Response(text, {
    status: upstream.status,
    statusText: upstream.statusText,
    headers,
  });
}

function rewriteBody(text, originBase, publicBase) {
  const escapedOrigin = originBase.replaceAll("/", "\\/");
  const escapedPublic = publicBase.replaceAll("/", "\\/");
  return text
    .split(originBase).join(publicBase)
    .split(escapedOrigin).join(escapedPublic)
    .split(ORIGIN_PREFIX + "/").join("/")
    .split(ORIGIN_PREFIX.replaceAll("/", "\\/") + "\\/").join("\\/");
}

function rewriteLocation(headers, originBase, publicBase) {
  const loc = headers.get("location");
  if (!loc) return;
  if (loc.startsWith(originBase)) {
    headers.set("location", publicBase + loc.slice(originBase.length));
  }
}

function rewriteCookies(sourceHeaders, targetHeaders) {
  let cookies = [];
  if (typeof sourceHeaders.getSetCookie === "function") {
    cookies = sourceHeaders.getSetCookie();
  } else {
    const one = sourceHeaders.get("set-cookie");
    if (one) cookies = [one];
  }
  if (!cookies.length) return;
  targetHeaders.delete("set-cookie");
  for (let cookie of cookies) {
    cookie = cookie
      .replace(/;\s*Domain=[^;]+/gi, "")
      .replace(/Path=\/staging-gestionale\/?/gi, "Path=/");
    targetHeaders.append("set-cookie", cookie);
  }
}
