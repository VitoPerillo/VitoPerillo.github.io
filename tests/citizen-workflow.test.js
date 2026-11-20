import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
const index = readFileSync(new URL("../src/index.js",import.meta.url),"utf8");
const admin = readFileSync(new URL("../public/admin.html",import.meta.url),"utf8");
const form = readFileSync(new URL("../public/segnala.html",import.meta.url),"utf8");
test("Citizen news is never published by automatic review job",()=>{
  const start=index.indexOf("async function reviewSubmission(env, id)");
  const end=index.indexOf("async function publishApprovedSubmission",start);
  assert.ok(start>0 && end>start);
  const block=index.slice(start,end);
  assert.match(block,/ugcModerationGate/);
  assert.match(block,/status='held'/);
  assert.doesNotMatch(block,/publishApprovedSubmission|INSERT INTO la_content/);
});
test("Admin approval of citizen news requires independent source and original copy",()=>{
  const start=index.indexOf('if (p === "/api/admin/submissions/review"');
  const end=index.indexOf('if (p === "/api/admin/ad-orders"',start);
  const block=index.slice(start,end);
  for(const keyword of ["verified_source_required","review_notes_required","fetchArticleDetails","editorialDraftGate","factGate","sensitive_report_requires_review","duplicate_story","publishApprovedSubmission(env, id, reviewedNews)"])assert.ok(block.includes(keyword),keyword);
  assert.ok(block.indexOf("fetchArticleDetails")<block.indexOf("publishApprovedSubmission(env, id, reviewedNews)"));
});
test("Citizen news does not directly publish raw citizen description",()=>{
  assert.match(index,/const title = reviewedNews\?\.headline \|\| String\(p\.title\)/);
  assert.match(index,/if \(s\.submission_type === "report" && \(!reviewedNews \|\| s\.content_id\)\) return/);
  assert.match(index,/reviewedNews\?\.source_url \|\| null/);
});
test("Editor must provide both factual source and new article in existing dashboard",()=>{
  for(const term of ["citizen-source-","citizen-title-","citizen-summary-","citizen-body-","citizen-notes-"])assert.ok(admin.includes(term),term);
  assert.ok(form.includes('name="source_url"'));
});
test("Logo favicon and EMERGENCY content paths are not rewritten",()=>{
  assert.ok(form.includes('/brand/logo-aho-roma.webp'));
  assert.ok(form.includes('/brand/favicon.ico'));
  assert.ok(admin.includes('/brand/favicon.ico'));
  assert.ok(!index.includes('DELETE FROM la_content'));
});
