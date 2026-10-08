const PLANS=Object.freeze({
 pegaso:{monthly:6.90,annual:69,questions:20,areas:4},
 fenice:{monthly:9.90,annual:99,questions:60,areas:8},
 andromeda:{monthly:14.90,annual:149,questions:150,areas:12}
});
const MOLLIE_API="https://api.mollie.com/v2";
const json=(d,s=200,h={})=>new Response(JSON.stringify(d),{status:s,headers:{"content-type":"application/json; charset=utf-8","cache-control":"no-store",...h}});
const cors=e=>({"access-control-allow-origin":e.PUBLIC_ORIGIN||"https://vitoperillo.github.io","access-control-allow-methods":"GET,POST,OPTIONS","access-control-allow-headers":"content-type,authorization,x-csrf-token"});
const valid=(p,c)=>!!PLANS[p]&&["monthly","annual"].includes(c)&&Number(PLANS[p][c])>0;
const interval=c=>c==="annual"?"12 months":"1 month";
const value=(p,c)=>Number(PLANS[p][c]).toFixed(2);
async function mollie(env,path,init={}){
 if(!env.MOLLIE_API_KEY)throw new Error("mollie_not_configured");
 const h=new Headers(init.headers||{});h.set("Authorization","Bearer "+env.MOLLIE_API_KEY);h.set("Content-Type","application/json");
 const r=await fetch(MOLLIE_API+path,{...init,headers:h});const d=await r.json().catch(()=>({}));
 if(!r.ok)throw new Error(d?.detail||d?.title||("mollie_http_"+r.status));return d;
}
async function profileByPublicId(env,id){return env.DB.prepare("SELECT id,public_id,email_ciphertext,plan,cadence,mollie_customer_id,subscription_id,subscription_status FROM profiles WHERE public_id=?").bind(id).first();}
async function logEvent(env,pid,type,payload){await env.DB.prepare("INSERT INTO events(profile_id,event_type,payload_json) VALUES(?,?,?)").bind(pid||null,type,JSON.stringify(payload||{})).run();}
export default{async fetch(request,env){
 const url=new URL(request.url),ch=cors(env);if(request.method==="OPTIONS")return new Response(null,{status:204,headers:ch});
 try{
  if(url.pathname==="/health"){let db=false;try{await env.DB.prepare("SELECT 1").first();db=true}catch(_){}return json({ok:db,service:"ritratto-stellare-api",payments:"mollie",payment_environment:env.MOLLIE_ENV||"test",mollie_configured:!!env.MOLLIE_API_KEY,live_authorized:env.LIVE_COMMERCIAL_AUTHORIZED==="true",db},db?200:503,ch)}
  if(url.pathname==="/v1/plans"&&request.method==="GET")return json({plans:PLANS},200,ch);

  if(url.pathname==="/v1/mollie/checkout"&&request.method==="POST"){
   if((env.MOLLIE_ENV||"test")==="live"&&env.LIVE_COMMERCIAL_AUTHORIZED!=="true")return json({ok:false,error:"live_commercial_not_authorized"},503,ch);
   const b=await request.json().catch(()=>({})),plan=String(b.plan||"").toLowerCase(),cadence=String(b.cadence||"").toLowerCase(),profileId=String(b.profile_id||"");
   if(!valid(plan,cadence))return json({ok:false,error:"invalid_plan"},400,ch);if(!profileId)return json({ok:false,error:"profile_required"},400,ch);
   const profile=await profileByPublicId(env,profileId);if(!profile)return json({ok:false,error:"profile_not_found"},404,ch);
   let customerId=profile.mollie_customer_id;
   if(!customerId){const customer=await mollie(env,"/customers",{method:"POST",body:JSON.stringify({metadata:{profile_id:profileId}})});customerId=customer.id;await env.DB.prepare("UPDATE profiles SET mollie_customer_id=?,updated_at=CURRENT_TIMESTAMP WHERE id=?").bind(customerId,profile.id).run();}
   const origin=(env.PUBLIC_ORIGIN||"https://vitoperillo.github.io").replace(/\/$/,""),apiOrigin=(env.API_ORIGIN||url.origin).replace(/\/$/,"");
   const payment=await mollie(env,"/payments",{method:"POST",body:JSON.stringify({amount:{currency:"EUR",value:value(plan,cadence)},customerId,sequenceType:"first",description:"Ritratto Stellare "+plan.toUpperCase()+" "+cadence,redirectUrl:origin+"/ritratto-stellare/?payment=return",webhookUrl:apiOrigin+"/v1/mollie/webhook",metadata:{profile_id:profileId,plan,cadence}})});
   await env.DB.prepare("UPDATE profiles SET pending_plan=?,pending_cadence=?,pending_mollie_customer_id=?,pending_payment_id=?,pending_started_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=?").bind(plan,cadence,customerId,payment.id,profile.id).run();
   return json({ok:true,payment_id:payment.id,checkout_url:payment?._links?.checkout?.href||null},200,ch);
  }

  if(url.pathname==="/v1/mollie/webhook"&&request.method==="POST"){
   const form=await request.formData().catch(()=>null),id=String(form?.get("id")||"");if(!id)return json({ok:false,error:"missing_payment_id"},400,ch);
   const payment=await mollie(env,"/payments/"+encodeURIComponent(id)),m=payment.metadata||{},profile=await profileByPublicId(env,String(m.profile_id||""));
   if(!profile||!valid(m.plan,m.cadence))return new Response("OK",{status:200,headers:ch});
   if(payment.status!=="paid"){await logEvent(env,profile.id,"mollie_payment_"+String(payment.status||"unknown"),{payment_id:id});return new Response("OK",{status:200,headers:ch})}
   const expected=value(m.plan,m.cadence);if(payment.amount?.currency!=="EUR"||payment.amount?.value!==expected){await logEvent(env,profile.id,"mollie_amount_mismatch",{payment_id:id});return json({ok:false,error:"amount_mismatch"},400,ch)}
   const prior=await env.DB.prepare("SELECT transaction_id FROM receipts WHERE transaction_id=?").bind(id).first();
   if(prior)return new Response("OK",{status:200,headers:ch});
   if(profile.subscription_id&&profile.subscription_status==="active"){await env.DB.prepare("INSERT OR IGNORE INTO receipts(profile_id,transaction_id,subscription_id,amount,currency,plan,cadence,payload_json) VALUES(?,?,?,?,?,?,?,?)").bind(profile.id,id,profile.subscription_id,expected,"EUR",m.plan,m.cadence,JSON.stringify({payment_id:id})).run();return new Response("OK",{status:200,headers:ch})}
   const customerId=payment.customerId||profile.mollie_customer_id;
   const sub=await mollie(env,"/customers/"+encodeURIComponent(customerId)+"/subscriptions",{method:"POST",body:JSON.stringify({amount:{currency:"EUR",value:expected},interval:interval(m.cadence),description:"Ritratto Stellare "+String(m.plan).toUpperCase(),webhookUrl:(env.API_ORIGIN||url.origin).replace(/\/$/,"")+"/v1/mollie/subscription-webhook",metadata:{profile_id:profile.public_id,plan:m.plan,cadence:m.cadence}})});
   await env.DB.prepare("UPDATE profiles SET plan=?,cadence=?,subscription_id=?,subscription_status='active',mollie_customer_id=?,pending_plan=NULL,pending_cadence=NULL,pending_payment_id=NULL,pending_started_at=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=?").bind(m.plan,m.cadence,sub.id,customerId,profile.id).run();
   await env.DB.prepare("INSERT OR IGNORE INTO receipts(profile_id,transaction_id,subscription_id,amount,currency,plan,cadence,payload_json) VALUES(?,?,?,?,?,?,?,?)").bind(profile.id,id,sub.id,expected,"EUR",m.plan,m.cadence,JSON.stringify({payment_id:id})).run();
   await logEvent(env,profile.id,"subscription_activated",{subscription_id:sub.id,plan:m.plan,cadence:m.cadence});return new Response("OK",{status:200,headers:ch});
  }

  if(url.pathname==="/v1/mollie/subscription-webhook"&&request.method==="POST"){
   const form=await request.formData().catch(()=>null),id=String(form?.get("id")||"");if(!id)return json({ok:false,error:"missing_payment_id"},400,ch);
   const payment=await mollie(env,"/payments/"+encodeURIComponent(id)),m=payment.metadata||{},profile=await profileByPublicId(env,String(m.profile_id||""));
   if(!profile)return new Response("OK",{status:200,headers:ch});
   if(payment.status==="paid"){await env.DB.prepare("INSERT OR IGNORE INTO receipts(profile_id,transaction_id,subscription_id,amount,currency,plan,cadence,payload_json) VALUES(?,?,?,?,?,?,?,?)").bind(profile.id,id,payment.subscriptionId||profile.subscription_id,payment.amount?.value||null,payment.amount?.currency||"EUR",profile.plan,profile.cadence,JSON.stringify({payment_id:id,renewal:true})).run();await env.DB.prepare("UPDATE profiles SET subscription_status='active',updated_at=CURRENT_TIMESTAMP WHERE id=?").bind(profile.id).run();await logEvent(env,profile.id,"subscription_renewed",{payment_id:id})}
   else if(["failed","expired","canceled"].includes(payment.status)){await env.DB.prepare("UPDATE profiles SET subscription_status=?,updated_at=CURRENT_TIMESTAMP WHERE id=?").bind("payment_"+payment.status,profile.id).run();await logEvent(env,profile.id,"subscription_payment_"+payment.status,{payment_id:id})}
   return new Response("OK",{status:200,headers:ch});
  }

  if(url.pathname==="/v1/mollie/cancel"&&request.method==="POST"){
   const b=await request.json().catch(()=>({})),profile=await profileByPublicId(env,String(b.profile_id||""));if(!profile?.mollie_customer_id||!profile?.subscription_id)return json({ok:false,error:"subscription_not_found"},404,ch);
   if(profile.subscription_status==="canceled")return json({ok:true,status:"canceled",idempotent:true},200,ch);
   await mollie(env,"/customers/"+encodeURIComponent(profile.mollie_customer_id)+"/subscriptions/"+encodeURIComponent(profile.subscription_id),{method:"DELETE"});
   await env.DB.prepare("UPDATE profiles SET subscription_status='canceled',updated_at=CURRENT_TIMESTAMP WHERE id=?").bind(profile.id).run();await logEvent(env,profile.id,"subscription_canceled",{subscription_id:profile.subscription_id});return json({ok:true,status:"canceled"},200,ch);
  }
  return json({ok:false,error:"not_found"},404,ch);
 }catch(e){return json({ok:false,error:String(e?.message||"internal_error")},500,ch)}
}};
