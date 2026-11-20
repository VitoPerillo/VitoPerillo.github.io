import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const css=readFileSync(new URL("../public/app.css",import.meta.url),"utf8");
const pages=readFileSync(new URL("../src/render/pages.js",import.meta.url),"utf8");
const section=css.slice(css.indexOf("/* AHÓ ROMA — compact mobile HOME only;"));
test("mobile patch is scoped at <=560px and occurs last",()=>{
  assert.ok(section.startsWith("/* AHÓ ROMA — compact mobile HOME only;"));
  assert.match(section,/@media \(max-width: 560px\)/);
  assert.ok(css.endsWith(section));
  assert.doesNotMatch(section,/\.brand-logo-image\s*\{/);
});
test("navigation is available on home even when existing mobile nav is hidden elsewhere",()=>{
  assert.match(section,/body:has\(main \.news-search-area\) \.site-header nav \{ display:flex/);
  assert.match(section,/body:has\(main \.news-search-area\) \.site-header nav a:not\(\.nav-cta\) \{ display:inline-flex/);
  for(const route of ['href="/#ultime"','href="/quartieri"','href="/eventi"','href="/segnala"'])
    assert.ok(pages.includes(route),route);
});
test("search retains accessible input label and GET /notizie semantics",()=>{
  assert.ok(pages.includes('action="/notizie" method="get" role="search"'));
  assert.ok(pages.includes('for="home-news-query"'));
  assert.match(section,/\.news-search-row input \{ height:40px; min-height:40px; padding:7px 9px; font-size:16px/);
  assert.match(section,/\.news-search-form > label \{ position:absolute/);
});
test("mobile sections are compact and articles are not removed",()=>{
  assert.match(section,/\.news-intro \{ padding:8px 0 9px/);
  assert.match(section,/\.breaking \{ padding-top:10px/);
  assert.match(section,/\.breaking \.lead-story \{ padding:13px/);
  assert.ok(pages.includes('<section class="breaking wrap" id="ultime">'));
  assert.ok(pages.includes('class="headline-list"'));
});
test("original brand/favicons and other routes remain in source",()=>{
  for(const asset of ["/brand/logo-aho-roma.webp","/brand/favicon.ico","/brand/apple-touch-icon.png","/brand/icon-192.png"])
    assert.ok(pages.includes(asset),asset);
  assert.ok(pages.includes("Segnala una notizia"));
});
