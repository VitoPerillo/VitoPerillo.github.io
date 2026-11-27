import { test } from 'node:test';
import assert from 'node:assert/strict';
import { DatabaseSync } from 'node:sqlite';
import { claimSourceWindow,selectDueSources,claimDueSource,POLL_LIMIT } from '../src/sources/source-schedule.js';
const now=new Date('2026-11-27T09:30:00Z');
function fixture(){
 const sqlite=new DatabaseSync(':memory:');
 sqlite.exec(`CREATE TABLE la_settings(key TEXT PRIMARY KEY,value TEXT,updated_at TEXT);
 CREATE TABLE la_sources(id INTEGER PRIMARY KEY,active INTEGER,usage_policy TEXT,parser_type TEXT,source_type TEXT,url TEXT,config_json TEXT,interval_minutes INTEGER,last_checked_at TEXT,last_success_at TEXT);`);
 const db={prepare(sql){const stmt=sqlite.prepare(sql);return{bind(...args){return{async run(){return{meta:{changes:Number(stmt.run(...args).changes)}}},async all(){return{results:stmt.all(...args)}}}}}}};
 const add=(id,{active=1,policy='discovery',parser='rss',type='official',url='https://example.org/feed',config='{}',interval=15,checked='2026-11-27T09:00:00Z',success=checked}={})=>sqlite.prepare('INSERT INTO la_sources VALUES(?,?,?,?,?,?,?,?,?,?)').run(id,active,policy,parser,type,url,config,interval,checked,success);
 return{sqlite,db,add};
}
test('one window owner, monotonic window, next slot available',async()=>{const {db}=fixture();assert.equal(await claimSourceWindow(db,now),true);assert.equal(await claimSourceWindow(db,now),false);assert.equal(await claimSourceWindow(db,new Date(now-900000)),false);assert.equal(await claimSourceWindow(db,new Date(+now+900000)),true);});
test('4 feeds plus 1 detail, hard limit five',async()=>{const{db,add}=fixture();for(let i=1;i<=7;i++)add(i);for(let i=8;i<=12;i++)add(i,{parser:'html',url:'https://example.org/story/'+i});const x=await selectDueSources(db,now);assert.equal(x.length,POLL_LIMIT);assert.deepEqual(x.map(r=>r.id),[1,2,3,4,8]);});
test('unused feed capacity goes to details',async()=>{const{db,add}=fixture();add(1);for(let i=2;i<=7;i++)add(i,{parser:'html'});assert.equal((await selectDueSources(db,now)).length,5);});
test('unused detail capacity goes to feeds',async()=>{const{db,add}=fixture();for(let i=1;i<=7;i++)add(i);assert.deepEqual((await selectDueSources(db,now)).map(r=>r.id),[1,2,3,4,5]);});
test('inactive, blocked, and not-due excluded',async()=>{const{db,add}=fixture();add(1,{active:0});add(2,{policy:'blocked'});add(3,{checked:'2026-11-27T09:29:00Z'});add(4);assert.deepEqual((await selectDueSources(db,now)).map(r=>r.id),[4]);});
test('failed fetch backs off, never-checked is eligible',async()=>{const{db,add}=fixture();add(1,{checked:'2026-11-27T09:10:00Z',success:'2026-11-27T08:30:00Z'});add(2,{checked:null,success:null});add(3,{checked:'2026-11-27T09:00:00Z',success:null});assert.deepEqual((await selectDueSources(db,now)).map(r=>r.id),[2,3]);});
test('claim is atomic and refuses a repeated claim',async()=>{const{db,add}=fixture();add(1);assert.equal(await claimDueSource(db,1,now),true);assert.equal(await claimDueSource(db,1,now),false);assert.equal((await selectDueSources(db,now)).length,0);});
test('municipality HTML listing is feed, malformed config does not crash',async()=>{const{db,add}=fixture();add(1,{parser:'html',url:'https://www.comune.roma.it/web/it/notizie.page?mun=municipio_xii',config:'broken'});add(2,{parser:'html',config:'{"poll_kind":"feed"}'});add(3,{parser:'html',config:'broken'});assert.deepEqual((await selectDueSources(db,now)).map(r=>[r.id,r.is_feed]),[[1,1],[2,1],[3,0]]);});
test('oldest due first within each group, no write on selection',async()=>{const{sqlite,db,add}=fixture();add(1,{checked:'2026-11-27T09:00:00Z'});add(2,{checked:'2026-11-27T08:00:00Z'});const before=sqlite.prepare('SELECT * FROM la_sources ORDER BY id').all();assert.deepEqual((await selectDueSources(db,now)).map(r=>r.id),[2,1]);assert.deepEqual(sqlite.prepare('SELECT * FROM la_sources ORDER BY id').all(),before);});
