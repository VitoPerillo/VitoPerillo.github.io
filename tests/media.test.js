import test from 'node:test';
import assert from 'node:assert/strict';
import {MediaStorageAdapter,KVMediaStorage,NullMediaStorage} from '../src/media/adapter.js';
class FakeKV{constructor(){this.m=new Map()} async put(k,v,o){this.m.set(k,{v:v instanceof Uint8Array?v:new Uint8Array(v),metadata:o?.metadata||{}})} async getWithMetadata(k){const x=this.m.get(k);return x?{value:x.v.buffer.slice(x.v.byteOffset,x.v.byteOffset+x.v.byteLength),metadata:x.metadata}:{value:null,metadata:null}} async delete(k){this.m.delete(k)}}
test('KV media put/get/delete roundtrip',async()=>{const kv=new FakeKV(),a=new MediaStorageAdapter(new KVMediaStorage(kv));await a.put('ugc/a.jpg',new Uint8Array([1,2,3]),'image/jpeg');const r=await a.get('ugc/a.jpg');assert.equal(r.mime,'image/jpeg');assert.deepEqual([...new Uint8Array(r.bytes)],[1,2,3]);await a.delete('ugc/a.jpg');assert.equal(await a.get('ugc/a.jpg'),null)});
test('Null media fails closed',async()=>{const a=new MediaStorageAdapter(new NullMediaStorage());assert.equal(a.available(),false);await assert.rejects(()=>a.put('x',new Uint8Array([1]),'image/jpeg'),/media_storage_unavailable/)})
