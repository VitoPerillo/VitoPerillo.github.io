const PLANS = Object.freeze({
  pegaso: { monthly: 6.90, annual: 69, questions: 20, areas: 4 },
  fenice: { monthly: 9.90, annual: 99, questions: 60, areas: 8, backendPlan: "orione" },
  andromeda: { monthly: 14.90, annual: 149, questions: 150, areas: 12 }
});

const MOLLIE_API = "https://api.mollie.com/v2";

function json(data, status = 200, extra = {}) {
  return new Response(JSON.stringify(data), { status, headers: { "content-type":"application/json; charset=utf-8", "cache-control":"no-store", ...extra }});
}
function cors(env) {
  return {
    "access-control-allow-origin": env.PUBLIC_ORIGIN || "https://vitoperillo.github.io",
    "access-control-allow-methods": "GET,POST,OPTIONS",
    "access-control-allow-headers": "content-type,authorization,x-csrf-token"
  };
}
async function mollie(env, path, init={}) {
  if (!env.MOLLIE_API_KEY) throw new Error("mollie_not_configured");
  const headers = new Headers(init.headers || {});
  headers.set("Authorization", "Bearer " + env.MOLLIE_API_KEY);
  headers.set("Content-Type", "application/json");
  const r = await fetch(MOLLIE_API + path, {...init, headers});
  const data = await r.json().catch(()=>({}));
  if (!r.ok) throw new Error(data?.detail || data?.title || ("mollie_http_"+r.status));
  return data;
}
function amount(plan,cadence){ return PLANS[plan]?.[cadence]; }
function valid(plan,cadence){ return !!PLANS[plan] && ["monthly","annual"].includes(cadence) && Number(amount(plan,cadence))>0; }
function interval(cadence){ return cadence === "annual" ? "12 months" : "1 month"; }

export default {
  async fetch(request, env) {
    const url = new URL(request.url);
    const ch = cors(env);
    if (request.method === "OPTIONS") return new Response(null,{status:204,headers:ch});

    if (url.pathname === "/health") {
      let db=false; try { await env.DB.prepare("SELECT 1 AS ok").first(); db=true; } catch(_){}
      return json({ok:db,service:"ritratto-stellare-api",payments:"mollie",payment_environment:env.MOLLIE_ENV||"test",live_authorized:env.LIVE_COMMERCIAL_AUTHORIZED==="true",db},db?200:503,ch);
    }
    if (url.pathname === "/v1/plans" && request.method === "GET") return json({plans:PLANS},200,ch);

    if (url.pathname === "/v1/mollie/checkout" && request.method === "POST") {
      if ((env.MOLLIE_ENV||"test")==="live" && env.LIVE_COMMERCIAL_AUTHORIZED!=="true") return json({ok:false,error:"live_commercial_not_authorized"},503,ch);
      const body=await request.json().catch(()=>({}));
      const plan=String(body.plan||"").toLowerCase(), cadence=String(body.cadence||"").toLowerCase();
      if(!valid(plan,cadence)) return json({ok:false,error:"invalid_plan"},400,ch);
      const profileId=body.profile_id ? String(body.profile_id) : "";
      if(!profileId) return json({ok:false,error:"profile_required"},400,ch);

      // Mollie recurring requires a customer + first payment/mandate before a recurring subscription.
      const customer=await mollie(env,"/customers",{method:"POST",body:JSON.stringify({name:body.name||undefined,email:body.email||undefined,metadata:{profile_id:profileId}})});
      const value=Number(amount(plan,cadence)).toFixed(2);
      const origin=(env.PUBLIC_ORIGIN||"https://vitoperillo.github.io").replace(/\/$/,"");
      const payment=await mollie(env,"/payments",{method:"POST",body:JSON.stringify({
        amount:{currency:"EUR",value},
        customerId:customer.id,
        sequenceType:"first",
        description:"Ritratto Stellare "+plan.toUpperCase()+" "+cadence,
        redirectUrl:origin+"/ritratto-stellare/?payment=return",
        webhookUrl:(env.API_ORIGIN||url.origin).replace(/\/$/,"")+"/v1/mollie/webhook",
        metadata:{profile_id:profileId,plan,cadence,customer_id:customer.id}
      })});
      return json({ok:true,payment_id:payment.id,checkout_url:payment?._links?.checkout?.href||null},200,ch);
    }

    if (url.pathname === "/v1/mollie/webhook" && request.method === "POST") {
      const form=await request.formData().catch(()=>null);
      const id=form?.get("id");
      if(!id) return json({ok:false,error:"missing_payment_id"},400,ch);
      const payment=await mollie(env,"/payments/"+encodeURIComponent(String(id)));
      const m=payment.metadata||{};
      if(payment.status==="paid" && valid(m.plan,m.cadence) && m.profile_id && payment.customerId){
        const value=Number(amount(m.plan,m.cadence)).toFixed(2);
        const sub=await mollie(env,"/customers/"+encodeURIComponent(payment.customerId)+"/subscriptions",{method:"POST",body:JSON.stringify({
          amount:{currency:"EUR",value}, interval:interval(m.cadence),
          description:"Ritratto Stellare "+String(m.plan).toUpperCase(),
          webhookUrl:(env.API_ORIGIN||url.origin).replace(/\/$/,"")+"/v1/mollie/subscription-webhook",
          metadata:{profile_id:String(m.profile_id),plan:m.plan,cadence:m.cadence}
        })});
        await env.DB.prepare("UPDATE profiles SET plan=?, cadence=?, subscription_id=?, subscription_status='active', mollie_customer_id=?, updated_at=CURRENT_TIMESTAMP WHERE public_id=?")
          .bind(m.plan,m.cadence,sub.id,payment.customerId,String(m.profile_id)).run();
      }
      return new Response("OK",{status:200,headers:ch});
    }

    if (url.pathname === "/v1/mollie/cancel" && request.method === "POST") {
      const body=await request.json().catch(()=>({}));
      const profileId=String(body.profile_id||"");
      const p=await env.DB.prepare("SELECT mollie_customer_id,subscription_id FROM profiles WHERE public_id=?").bind(profileId).first();
      if(!p?.mollie_customer_id||!p?.subscription_id) return json({ok:false,error:"subscription_not_found"},404,ch);
      await mollie(env,"/customers/"+encodeURIComponent(p.mollie_customer_id)+"/subscriptions/"+encodeURIComponent(p.subscription_id),{method:"DELETE"});
      await env.DB.prepare("UPDATE profiles SET subscription_status='canceled', updated_at=CURRENT_TIMESTAMP WHERE public_id=?").bind(profileId).run();
      return json({ok:true,status:"canceled"},200,ch);
    }

    return json({ok:false,error:"not_found"},404,ch);
  }
};
