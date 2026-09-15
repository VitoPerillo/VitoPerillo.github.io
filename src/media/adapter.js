export class MediaStorageAdapter {
  constructor(provider){ this.provider=provider; }
  static fromEnv(env){
    if(env.MEDIA_KV) return new MediaStorageAdapter(new KVMediaStorage(env.MEDIA_KV));
    return new MediaStorageAdapter(new NullMediaStorage());
  }
  async put(key,bytes,mime){ return this.provider.put(key,bytes,mime); }
  async get(key){ return this.provider.get(key); }
  async delete(key){ return this.provider.delete(key); }
  available(){ return this.provider.available(); }
}

export class KVMediaStorage {
  constructor(kv){ this.kv=kv; }
  available(){ return true; }
  async put(key,bytes,mime){
    await this.kv.put(key,bytes,{metadata:{mime,created_at:new Date().toISOString()}});
    return {key};
  }
  async get(key){
    const r=await this.kv.getWithMetadata(key,{type:'arrayBuffer',cacheTtl:3600});
    if(!r?.value) return null;
    return {bytes:r.value,mime:String(r.metadata?.mime||'application/octet-stream')};
  }
  async delete(key){ await this.kv.delete(key); }
}

export class NullMediaStorage {
  available(){ return false; }
  async put(){ throw new Error('media_storage_unavailable'); }
  async get(){ return null; }
  async delete(){ return; }
}
