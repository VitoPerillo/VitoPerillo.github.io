import test from "node:test";
import assert from "node:assert/strict";
import {readFileSync} from "node:fs";
const css=readFileSync(new URL("../public/app.css",import.meta.url),"utf8");
const page=readFileSync(new URL("../src/render/pages.js",import.meta.url),"utf8");
const delta=css.slice(css.indexOf("/* AHÓ ROMA — MOBILE Home: remove"));
test("mobile-only CSS removes intro and news header, no article clipping",()=>{
  assert.ok(delta.startsWith("/* AHÓ ROMA — MOBILE Home"));
  assert.match(delta,/@media \(max-width:560px\)/);
  assert.match(delta,/body:has\(main \.news-search-area\) \.news-intro \{ display:none/);
  assert.match(delta,/body:has\(main \.news-search-area\) \.breaking > \.compact-head \{ display:none/);
  assert.doesNotMatch(delta,/\.article\b|\.news-history\b/);
});
test("geolocation control exists as non-submit 40px accessible button in search",()=>{
  assert.match(page,/id="geoBtnMobile" type="button" title="Trova il mio quartiere" aria-label="Trova il mio quartiere"/);
  assert.match(delta,/flex:0 0 40px/);
  assert.match(page,/id="geoStatusMobile"[^>]*role="status" aria-live="polite"/);
  assert.match(page,/action="\/notizie" method="get" role="search"/);
});
test("desktop fallback keeps original controls, mobile handler uses existing geo logic",()=>{
  assert.match(page,/id="geoBtn" type="button">📍 Trova il mio quartiere/);
  assert.match(page,/mobileBtn\?\.addEventListener\('click', findMyArea\)/);
  assert.match(page,/btn\?\.addEventListener\('click', findMyArea\)/);
  assert.match(page,/mobileBtn\.onclick=\(\)=>location\.href/);
  assert.match(page,/status\.innerHTML = message;[\s\S]*mobileStatus\.innerHTML = message/);
});
test("manual zone selector stays accessible in top navigation",()=>{
  assert.match(page,/<nav><a href="\/#ultime">Ultime<\/a><a href="\/quartieri">Quartieri<\/a>/);
  assert.match(page,/href="\/quartieri">Scegli manualmente<\/a>/);
});
test("lead, excerpts, logo/favicon and all other content unmodified",()=>{
  assert.match(page,/\$\{leadMarkup\}/);
  assert.match(page,/\$\{sideMarkup\}/);
  assert.match(page,/\$\{html\(lead\.summary \|\| ""\)\}/);
  for(const v of ["/brand/logo-aho-roma.webp","/brand/favicon.ico","/brand/apple-touch-icon.png","/brand/icon-192.png"]){assert.ok(page.includes(v));}
  assert.match(css,/-webkit-line-clamp:3/);
});
