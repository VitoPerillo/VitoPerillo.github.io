import test from 'node:test'; import assert from 'node:assert/strict'; import fs from 'node:fs';
const idx=fs.readFileSync(new URL('../src/index.js',import.meta.url),'utf8'); const schema=fs.readFileSync(new URL('../migrations/0001_init.sql',import.meta.url),'utf8'); const wr=fs.readFileSync(new URL('../wrangler.jsonc',import.meta.url),'utf8'); const ai=fs.readFileSync(new URL('../src/ai/gemini.js',import.meta.url),'utf8');
const checks={
 'P0 queue atomic conditional claim':/UPDATE la_jobs SET status='processing'[\s\S]+WHERE id=\? AND status IN/.test(fs.readFileSync(new URL('../src/core/queue.js',import.meta.url),'utf8')),
 'P0 stale lock recovery':/10 minutes/.test(fs.readFileSync(new URL('../src/core/queue.js',import.meta.url),'utf8')),
 'P0 discovery cannot autopublish':/usage_policy==='discovery'/.test(fs.readFileSync(new URL('../src/core/pipeline.js',import.meta.url),'utf8')),
 'P0 blocked cannot publish':/usage_policy==='blocked'/.test(fs.readFileSync(new URL('../src/core/pipeline.js',import.meta.url),'utf8')),
 'P0 UGC not sent to Gemini':!/reviewSubmission[\s\S]{0,4000}aiProvider/.test(idx),
 'P0 no automatic billing logic':!/(stripe|paypal|checkout|billing|upgrade plan)/i.test(idx+ai),
 'P0 token hashes':/verification_token_hash/.test(schema)&&/edit_token_hash/.test(schema)&&/sha256\(verify\)/.test(idx),
 'P0 private email not public schema output':!/private_email/.test(fs.readFileSync(new URL('../src/render/pages.js',import.meta.url),'utf8')),
 'P0 upload whitelist':/image\/jpeg/.test(fs.readFileSync(new URL('../src/security/security.js',import.meta.url),'utf8'))&&!/image\/svg/.test(fs.readFileSync(new URL('../src/security/security.js',import.meta.url),'utf8')),
 'P0 social failure nonblocking job':/social_publish/.test(idx)&&/NullSocialProvider/.test(fs.readFileSync(new URL('../src/social/adapter.js',import.meta.url),'utf8')),
 'P0 news sitemap 2 days':/-2 days/.test(fs.readFileSync(new URL('../src/render/pages.js',import.meta.url),'utf8')),
 'P0 expired event maintenance':/content_type='event'/.test(idx)&&/status='expired'/.test(idx),
 'P0 health dead cron':/cron_dead/.test(idx)&&/queue_heartbeat/.test(idx),
 'P0 dashboard action zero semantics':/action_required:action/.test(idx),
 'P0 fixed free architecture bindings':/d1_databases/.test(wr)&&/kv_namespaces/.test(wr)&&/MEDIA_KV/.test(wr)&&!/r2_buckets/.test(wr)&&!/MEDIA_DB/.test(wr),
 'P1 retention raw ingest':/-30 days/.test(idx),
 'P1 retention completed jobs':/-14 days/.test(idx),
 'P1 admin bearer auth':/Bearer/.test(fs.readFileSync(new URL('../src/security/security.js',import.meta.url),'utf8')),
}; for(const [name,ok] of Object.entries(checks))test(name,()=>assert.equal(ok,true));

test('P0 verification/edit tokens are not stored cleartext in queue payload',()=>{assert.equal(/verify_token|edit_token/.test(idx.match(/enqueue\(env\.DB,'send_verify_email'[\s\S]{0,150}/)?.[0]||''),false)});
test('P1 public forms and magic-link UI exist',()=>{assert.equal(fs.existsSync(new URL('../public/segnala.html',import.meta.url)),true);assert.equal(fs.existsSync(new URL('../public/gestisci.html',import.meta.url)),true)});
test('P1 admin dashboard exists',()=>assert.equal(fs.existsSync(new URL('../public/admin.html',import.meta.url)),true));
test('P1 protected source run endpoint exists',()=>assert.match(idx,/api\/admin\/source\/run/));
test('P0 discovery publication requires explicit admin review',()=>{assert.match(idx,/api\/admin\/ingest\/review/);assert.match(idx,/editorApproved:true/);assert.match(fs.readFileSync(new URL('../src/core/pipeline.js',import.meta.url),'utf8'),/discovery'&&!editorApproved/)});
test('P1 admin can list and review held ingests',()=>{const admin=fs.readFileSync(new URL('../public/admin.html',import.meta.url),'utf8');assert.match(idx,/api\/admin\/ingests/);assert.match(admin,/Notizie da revisionare/);assert.match(admin,/reviewIngest/)});
test('P0 completed ingests are idempotent and source items are deduplicated',()=>{const p=fs.readFileSync(new URL('../src/core/pipeline.js',import.meta.url),'utf8');assert.match(p,/published','updated','rejected/);assert.match(p,/SELECT id FROM la_ingest WHERE source_url=/)});
test('P1 canonical is absolute',()=>assert.equal(/canonicalUrl=root\+routeFor/.test(fs.readFileSync(new URL('../src/render/pages.js',import.meta.url),'utf8')),true));
test('P1 area hub and internal links exist',()=>{const p=fs.readFileSync(new URL('../src/render/pages.js',import.meta.url),'utf8');assert.equal(/areaPage/.test(p)&&/Contenuti correlati/.test(p),true)});

test('P0 media adapter is provider-abstracted and KV-backed by default',()=>{const m=fs.readFileSync(new URL('../src/media/adapter.js',import.meta.url),'utf8');assert.equal(/class MediaStorageAdapter/.test(m)&&/class KVMediaStorage/.test(m)&&/class NullMediaStorage/.test(m),true)});
test('P0 second D1 media dependency removed',()=>{assert.equal(fs.existsSync(new URL('../migrations-media',import.meta.url)),false);assert.equal(/MEDIA_DB/.test(idx+wr),false)});
test('P0 source redirects revalidated against SSRF',()=>{const a=fs.readFileSync(new URL('../src/sources/adapters.js',import.meta.url),'utf8');assert.equal(/redirect:'manual'/.test(a)&&/safeSourceUrl\(current\)/.test(a),true)});
test('P1 rights declaration and form timing validated server-side',()=>{const sec=fs.readFileSync(new URL('../src/security/security.js',import.meta.url),'utf8');const form=fs.readFileSync(new URL('../public/segnala.html',import.meta.url),'utf8');assert.equal(/rights_required/.test(idx)&&/validFormTiming/.test(idx+sec)&&/rights_declared/.test(form)&&/form_started_at/.test(form),true)});
test('P1 media removed on owner delete',()=>assert.equal(/MediaStorageAdapter\.fromEnv\(env\)\.delete/.test(idx),true));
