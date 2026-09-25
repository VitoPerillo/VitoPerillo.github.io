(()=>{"use strict";
const KEY="rs_buy_intent_v2";
const PLANS=["pegaso","orione","andromeda"];
const CADS=["monthly","annual"];
const LABELS={
  pegaso:{name:"PEGASO",monthly:"€6,90/mese",annual:"€69/anno"},
  orione:{name:"ORIONE",monthly:"€9,90/mese",annual:"€99/anno"},
  andromeda:{name:"ANDROMEDA",monthly:"€14,90/mese",annual:"€149/anno"}
};
const $=(s,r=document)=>r.querySelector(s);
const $$=(s,r=document)=>[...r.querySelectorAll(s)];
function goodPlan(v){return PLANS.includes(v)}
function goodCad(v){return CADS.includes(v)}
function putIntent(plan,cadence){
  if(!goodPlan(plan)||!goodCad(cadence))return null;
  const i={plan,cadence,ts:Date.now()};
  localStorage.setItem(KEY,JSON.stringify(i));
  return i;
}
function getIntent(){
  try{
    const q=new URLSearchParams(location.search);
    const p=(q.get("buy")||q.get("plan")||"").toLowerCase();
    const c=(q.get("cadence")||"").toLowerCase();
    if(goodPlan(p)&&goodCad(c))return putIntent(p,c);
    const raw=localStorage.getItem(KEY); if(!raw)return null;
    const i=JSON.parse(raw);
    if(!goodPlan(i.plan)||!goodCad(i.cadence)||Date.now()-Number(i.ts||0)>86400000){
      localStorage.removeItem(KEY); return null;
    }
    return i;
  }catch(e){return null}
}
function label(i){return LABELS[i.plan].name+" · "+LABELS[i.plan][i.cadence]}
function currentCadence(){
  return $(".rs-toggle button.active")?.dataset.cad || "monthly";
}
function api(path,opt={}){
  const cfg=window.RS_APP||{};
  const rest=cfg.rest||"/wp-json/ritratto-stellare/v1/";
  opt.headers=Object.assign({
    "Content-Type":"application/json",
    "X-WP-Nonce":cfg.nonce||"",
    "X-RS-CSRF":cfg.csrf||""
  },opt.headers||{});
  return fetch(rest+path,opt).then(async r=>{
    let j={}; try{j=await r.json()}catch(e){}
    if(!r.ok)throw new Error(j.message||j.code||"Operazione non riuscita");
    return j;
  });
}
function directBuyCopy(i){
  const start=$("#rs-start-form");
  if(start){
    const sec=start.closest("section")||start.parentElement;
    const h=sec&&$("h2",sec);
    const ps=sec?$$("p",sec):[];
    const submit=$('[type="submit"]',start);
    if(h)h.textContent="Completa i dati per acquistare "+label(i);
    if(ps[0])ps[0].textContent="Inserisci i dati necessari per creare il tuo Ritratto Stellare. Dopo la verifica dell’email continuerai al pagamento PayPal.";
    if(submit)submit.textContent="CONTINUA ALL’ACQUISTO";
    if(sec&&!$(".rs-direct-buy-note",sec)){
      const n=document.createElement("p");
      n.className="rs-direct-buy-note";
      n.style.cssText="padding:12px 14px;border:1px solid #d9bd7a;border-radius:14px;background:#fff9e9;color:#3b2a13;font-weight:700";
      n.textContent="Hai scelto "+label(i)+". FENICE non è obbligatoria: questo percorso prepara direttamente l’acquisto selezionato.";
      start.parentNode.insertBefore(n,start);
    }
    $$("*",sec||document).forEach(el=>{
      if(el.children.length===0&&/FENICE\s*·\s*30\s*GIORNI\s*GRATIS/i.test(el.textContent||"")){
        el.textContent="ACQUISTO DIRETTO · "+label(i);
      }
    });
  }

  const access=$$("a").find(a=>/HO GIÀ UN ACCESSO/i.test(a.textContent||""));
  if(access)access.href="/il-mio-cielo/?buy="+encodeURIComponent(i.plan)+"&cadence="+encodeURIComponent(i.cadence);

  const loginNote=$$("p").find(p=>/Primo accesso\? Attiva prima FENICE gratis/i.test(p.textContent||""));
  if(loginNote)loginNote.textContent="Acquisto scelto: "+label(i)+". Verifica la tua email per continuare; se non hai ancora un profilo, torna alla pagina precedente e inserisci i dati necessari al Ritratto.";
}
function rewritePaidButtons(){
  $$(".rs-plan").forEach(card=>{
    const p=card.dataset.plan,btn=$(".rs-plan-buy",card);
    if(!btn||!goodPlan(p))return;
    if(!btn.disabled)btn.textContent="ACQUISTA "+LABELS[p].name;
  });
}
async function routeKnownUser(i){
  if(!location.pathname.startsWith("/ritratto-stellare"))return;
  try{
    await api("me");
    location.replace("/il-mio-cielo/?buy="+encodeURIComponent(i.plan)+"&cadence="+encodeURIComponent(i.cadence));
  }catch(e){}
}
function showPurchaseBanner(i){
  if($("#rs-direct-purchase-banner"))return;
  const host=$("#rs-account")||$("main")||document.body;
  const box=document.createElement("div");
  box.id="rs-direct-purchase-banner";
  box.style.cssText="position:sticky;top:10px;z-index:9999;margin:12px auto;padding:14px 16px;max-width:760px;border-radius:16px;background:#17102d;color:#fff;border:1px solid #dfbf78;box-shadow:0 12px 35px rgba(0,0,0,.3)";
  box.innerHTML="<strong>Acquisto scelto: "+label(i)+"</strong><br><span>Controlla il piano qui sotto e premi il pulsante per aprire PayPal.</span>";
  host.prepend(box);
}
function prepareIntentCheckout(i){
  if(!$("#rs-account-data"))return false;
  const toggle=$('.rs-toggle button[data-cad="'+i.cadence+'"]');
  if(toggle&&!toggle.classList.contains("active"))toggle.click();
  showPurchaseBanner(i);
  let tries=0;
  const t=setInterval(()=>{
    tries++;
    const card=$('.rs-plan[data-plan="'+i.plan+'"]');
    const btn=card&&$(".rs-plan-buy",card);
    if(btn&&!btn.disabled){
      clearInterval(t);
      btn.textContent="CONTINUA SU PAYPAL · "+label(i);
      card.scrollIntoView({behavior:"smooth",block:"center"});
      card.style.outline="2px solid #dfbf78";
      card.style.outlineOffset="4px";
    } else if(tries>24) clearInterval(t);
  },500);
  return true;
}
async function startCheckout(plan,cadence,btn){
  const i=putIntent(plan,cadence);
  if(!$("#rs-account-data")){
    location.href="/ritratto-stellare/?buy="+encodeURIComponent(plan)+"&cadence="+encodeURIComponent(cadence)+"#rs-fenice";
    return;
  }
  const old=btn.textContent;
  btn.disabled=true; btn.textContent="PREPARO PAYPAL…";
  let payWin=null;
  try{payWin=window.open("about:blank","rs_paypal_checkout")}catch(e){}
  try{
    const j=await api("subscribe",{method:"POST",body:JSON.stringify({plan,cadence})});
    const dest=j.approve||j.redirect;
    if(!dest)throw new Error("Link PayPal non disponibile");
    if(payWin){
      payWin.location.href=dest;
    }else{
      const banner=$("#rs-direct-purchase-banner")||document.body;
      const a=document.createElement("a");
      a.href=dest;a.target="_blank";a.rel="noopener";a.textContent="APRI PAYPAL";
      a.style.cssText="display:inline-block;margin-top:10px;padding:10px 16px;border-radius:999px;background:#dfbf78;color:#24180a;font-weight:800";
      banner.appendChild(a);
    }
    btn.textContent="PAYPAL APERTO · ATTENDO CONFERMA";
    pollActivation(i,btn,old);
  }catch(err){
    if(payWin)try{payWin.close()}catch(e){}
    btn.disabled=false;btn.textContent=old;
    alert(err.message);
  }
}
function pollActivation(i,btn,old){
  let n=0;
  const t=setInterval(async()=>{
    n++;
    try{
      const me=await api("me");
      if(me&&me.plan===i.plan&&(i.cadence!=="annual"||me.cadence==="annual")){
        clearInterval(t);
        localStorage.removeItem(KEY);
        btn.disabled=false;btn.textContent="ACQUISTO ATTIVO";
        const b=$("#rs-direct-purchase-banner");
        if(b)b.innerHTML="<strong>Pagamento confermato.</strong><br>Il piano "+label(i)+" è attivo.";
        setTimeout(()=>location.reload(),1200);
      }
    }catch(e){}
    if(n>=100){
      clearInterval(t);
      btn.disabled=false;btn.textContent=old;
    }
  },3000);
}
document.addEventListener("click",e=>{
  const btn=e.target.closest&&e.target.closest(".rs-plan-buy");
  if(!btn)return;
  const card=btn.closest(".rs-plan"),plan=(btn.dataset.plan||card?.dataset.plan||"").toLowerCase();
  if(!goodPlan(plan))return;
  const cadence=currentCadence();
  e.preventDefault();e.stopImmediatePropagation();
  startCheckout(plan,cadence,btn);
},true);

function boot(){
  rewritePaidButtons();
  const i=getIntent();
  if(!i)return;
  directBuyCopy(i);
  prepareIntentCheckout(i);
  routeKnownUser(i);
}
if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",boot);else boot();
setTimeout(boot,900);setTimeout(boot,2200);
})();