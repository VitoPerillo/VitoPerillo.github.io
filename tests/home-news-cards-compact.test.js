import test from "node:test";
import assert from "node:assert/strict";
import {readFileSync} from "node:fs";
const css=readFileSync(new URL("../public/app.css",import.meta.url),"utf8");
const page=readFileSync(new URL("../src/render/pages.js",import.meta.url),"utf8");
const delta=css.slice(css.indexOf("/* AHÓ ROMA — mobile Home news cards only"));
test("CSS delta is terminal and restricted to max-width 560",()=>{
 assert.ok(delta.startsWith("/* AHÓ ROMA — mobile Home news cards only"));
 assert.match(delta,/@media \(max-width:560px\)/);
 assert.ok(css.trimEnd().endsWith(delta.trimEnd()));
});
test("All modified news selectors are scoped to Home, not /notizie detail",()=>{
 const rules=[...delta.matchAll(/^\s*([^{}]+)\s*\{[^{}]*\}/gm)].map(m=>m[1].trim()).filter(s=>s.startsWith("body:has"));
 assert.equal(rules.length,9);
 assert.ok(rules.every(s=>s.startsWith("body:has(main .news-search-area) ")));
 assert.doesNotMatch(delta,/\.article\b/);
});
test("Lead and further news excerpt are visually clamped to 3 lines",()=>{
 for(const selector of [".breaking .lead-story p",".latest-grid .card p"]){
  const clause=delta.split("\n").find(line=>line.includes(selector+" {"));
  assert.ok(clause,selector);
  assert.match(clause,/-webkit-line-clamp:3/);
  assert.match(clause,/-webkit-box-orient:vertical/);
  assert.match(clause,/overflow:hidden/);
 }
});
test("Titles and spacing change without editing source news or links",()=>{
 assert.match(delta,/lead-story h1 \{ font-size:21px/);
 assert.match(delta,/headline-row h2 \{ font-size:17px/);
 assert.match(delta,/latest-grid .card h3 \{ font-size:18px/);
 assert.match(page,/\$\{html\(lead\.summary \|\| ""\)\}/);
 assert.match(page,/\$\{html\(routeFor\(lead\)\)\}/);
});
test("Official brand assets and existing navigation remain in site source",()=>{
 for(const asset of ["/brand/logo-aho-roma.webp","/brand/favicon.ico","/brand/apple-touch-icon.png","/brand/icon-192.png"])
  assert.ok(page.includes(asset));
 for(const route of ["/#ultime","/quartieri","/eventi","/segnala"])
  assert.ok(page.includes(`href="${route}"`));
});
