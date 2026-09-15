export class NullProvider {
  async generateArticle(record){ return {headline:record.title, summary:String(record.text||'').slice(0,220), body:record.text||'', area_id:record.area_id||null, category_id:record.category_id||null, facts:[], what_changes:'', valid_from:record.valid_from||record.original_date||null, valid_until:record.valid_until||null, confidence:50, social_text:record.title}; }
  async classifyRisk(){ return {level:'YELLOW'}; }
}

export class GeminiProvider {
  constructor(env){ this.env=env; }
  async call(payload){
    if(!this.env.GEMINI_API_KEY) throw new Error('gemini_missing_key');
    const model=encodeURIComponent(this.env.GEMINI_MODEL||'gemini-3.5-flash');
    const r=await fetch(`https://generativelanguage.googleapis.com/v1beta/models/${model}:generateContent`,{
      method:'POST', headers:{'content-type':'application/json','x-goog-api-key':this.env.GEMINI_API_KEY}, body:JSON.stringify(payload)
    });
    if(!r.ok) throw new Error(`gemini_http_${r.status}`);
    return r.json();
  }
  async generateArticle(record){
    const minimized={title:record.title,text:record.text,source_url:record.source_url,area_id:record.area_id,category_id:record.category_id,original_date:record.original_date,valid_from:record.valid_from,valid_until:record.valid_until};
    const prompt=`Restituisci SOLO JSON valido con campi headline,summary,body,facts,what_changes,valid_from,valid_until,confidence,social_text. Non inventare nomi, numeri, date, orari, prezzi o indirizzi. Scrivi una notizia locale utile e non clickbait. Dati pubblici verificati: ${JSON.stringify(minimized)}`;
    const data=await this.call({contents:[{parts:[{text:prompt}]}],generationConfig:{responseMimeType:'application/json'}});
    const text=data?.candidates?.[0]?.content?.parts?.map(p=>p.text||'').join('')||'';
    let out; try{out=JSON.parse(text);}catch{throw new Error('gemini_invalid_json');}
    if(!out.headline||!out.body) throw new Error('gemini_invalid_shape');
    return out;
  }
  async classifyRisk(record){
    const publicOnly={title:record.title,text:record.text,source_url:record.source_url};
    const prompt=`Classifica il rischio editoriale come GREEN, YELLOW o RED. Rispondi SOLO JSON {"level":"..."}. Dati pubblici: ${JSON.stringify(publicOnly)}`;
    const data=await this.call({contents:[{parts:[{text:prompt}]}],generationConfig:{responseMimeType:'application/json'}});
    const text=data?.candidates?.[0]?.content?.parts?.map(p=>p.text||'').join('')||''; let out;
    try{out=JSON.parse(text);}catch{throw new Error('gemini_invalid_json');}
    return {level:['GREEN','YELLOW','RED'].includes(out.level)?out.level:'RED'};
  }
}

export function aiProvider(env){ return env.AI_PROVIDER==='gemini' ? new GeminiProvider(env) : new NullProvider(); }
