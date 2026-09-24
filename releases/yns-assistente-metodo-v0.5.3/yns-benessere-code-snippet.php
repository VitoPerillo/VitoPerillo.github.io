if (!defined('ABSPATH')) { return; }

final class YNS_Benessere_V053 {
  const M='https://www.yoganostress.it/metodo-yoganostress/';
  const F='https://www.yoganostress.it/benvenuto-alla-tua-prima-esperienza-yoganostress/';
  const I='https://www.yoganostress.it/sessioni-individuali-yoganostress/';
  const E='https://www.yoganostress.it/percorsi-eventi-roma/';
  const H='https://www.yoganostress.it/hatha-yoga-roma-postura-ansia-stress/';
  const N='https://www.yoganostress.it/yoga-nidra-roma-stress-ansia-sonno/';
  const CO='https://www.yoganostress.it/coaching-individuale-di-chiarezza-interiore/';
  const R='https://www.yoganostress.it/il-reiki-e-un-trattamento-di-benessere-olistico-di-origine-orientale-olistico-significa-che-parte-dal-presupposto-che-spirito-e-corpo-sono-due-aspetti-della-persona-collegati-fra-l/';
  const S='https://www.yoganostress.it/affitto-sala-yoga-a-roma-per-corsi-eventi-olistici/';
  const C='https://www.yoganostress.it/collaborazioni/';
  const GAP='yns_benessere_content_gaps';

  static function init(){ self::cleanup_home(); add_action('rest_api_init',[__CLASS__,'rest']); add_action('wp_footer',[__CLASS__,'foot'],90); add_shortcode('yns_benessere',[__CLASS__,'box']); }
  static function cleanup_home(){
    if(get_option('ynsba_cleanup_052')) return;
    $id=(int)get_option('page_on_front'); if(!$id) return;
    $p=get_post($id); if(!$p) return;
    $c=(string)$p->post_content;
    $start=strpos($c,'<div class="yns-orientatore-benessere"');
    if($start===false){ update_option('ynsba_cleanup_052',1,false); return; }
    $pos=$start; $depth=0; $end=false; $len=strlen($c);
    while($pos<$len){
      $o=strpos($c,'<div',$pos); $cl=strpos($c,'</div>',$pos);
      if($o!==false && ($cl===false || $o<$cl)){ $depth++; $pos=$o+4; continue; }
      if($cl!==false){ $depth--; $pos=$cl+6; if($depth===0){ $end=$pos; break; } continue; }
      break;
    }
    if($end!==false){
      $new=substr($c,0,$start).substr($c,$end);
      if($new!==$c) wp_update_post(['ID'=>$id,'post_content'=>$new]);
      update_option('ynsba_cleanup_052',1,false);
      wp_cache_flush();
    }
  }
  static function n($s){ return mb_strtolower(remove_accents(wp_strip_all_tags((string)$s))); }

  static function cat($q){
    $s=self::n($q);
    $m=[
      'health'=>['ernia','diagnosi','l5','s1','sciatica','protrusione','patologia','dolore forte','fase acuta','osteoporosi','artrosi','artrite','fibromialgia','scoliosi','frattura','discopatia'],
      'sala'=>['affitto sala','affittare sala','utilizzo sala','utilizzare la sala','usare la sala','uso sala','gestione sala','cerco una sala','spazio per evento','spazio per corso','sala yoga'],
      'collab'=>['collaborazione','collaborare','proposta professionale','proporre un corso','sono un insegnante','sono un operatore','lavorare con voi','partnership'],
      'nidra'=>['mindfulness','meditazione','meditare','consapevolezza','insonnia','sonno','dormire','mente sempre attiva'],
      'individual'=>['individuale','lezione privata','lezione personale','uno a uno','seguito personalmente','percorso personalizzato'],
      'coach'=>['mental coach','mental coaching','coaching','chiarezza','decisione','decisioni','cambiamento','blocco mentale'],
      'reiki'=>['reiki','trattamento energetico','riequilibrio energetico','attivazione reiki','corso reiki'],
      'events'=>['ritiro','ritiri','bagno sonoro','campane tibetane','workshop','seminario','evento','eventi','approfondimento'],
      'hatha'=>['schiena','lombar','postura','posturale','spalle','colonna','cervicale','rigid','allineamento','mobilita','acciacchi'],
      'stress'=>['stress','ansia','agitazione','rimugin','preoccup','rilassamento','sovraccarico'],
      'wellbeing'=>['benessere','equilibrio','stare meglio','sentirmi meglio','energia','centratura'],
      'online'=>['online','da casa','a distanza','on demand'],
      'schedule'=>['orario','orari','domani','oggi','prima esperienza','prenot'],
      'fees'=>['prezzo','costo','quota','abbonamento','quanto costa'],
      'noyoga'=>['non voglio yoga','non cerco yoga','senza yoga','non mi interessa yoga']
    ];
    foreach($m as $c=>$ws) foreach($ws as $w) if(mb_strpos($s,$w)!==false) return $c;
    return 'other';
  }

  static function data($c){
    $x=[
      'health'=>['Nel Metodo Yoganostress non diamo una lista di posizioni in base a una diagnosi: conta soprattutto come la pratica viene adattata alla persona. Prima della Prima Esperienza parlane con noi e concorda un colloquio: la segreteria riceve solo su appuntamento. Se la situazione è acuta o presenta sintomi importanti, confrontati anche con il professionista sanitario che ti segue.',self::M,'Scopri il Metodo Yoganostress'],
      'sala'=>['Se cerchi uno spazio per corsi, eventi, seminari, trattamenti o attività professionali, Yoganostress mette a disposizione la propria sala a Roma Monteverde secondo disponibilità e modalità da concordare.',self::S,'Scopri utilizzo e disponibilità della sala'],
      'collab'=>['Se vuoi proporre una collaborazione professionale, un corso, un evento o un progetto con Yoganostress, puoi inviarci la tua proposta attraverso la sezione Collaborazioni.',self::C,'Proponi una collaborazione'],
      'nidra'=>['Se cerchi meditazione o mindfulness, nel Metodo Yoganostress ti orientiamo soprattutto verso Yoga Nidra. Nel corso integriamo anche pratiche di mindfulness: la consapevolezza è il filo conduttore. È guidato e si pratica generalmente da sdraiati.',self::N,'Scopri Yoga Nidra'],
      'individual'=>['Se preferisci essere seguito personalmente puoi scegliere un percorso individuale: lezioni personali di yoga, lavoro posturale, Yoga Nidra, Reiki, Mental Coaching o un percorso integrato del Metodo Yoganostress.',self::I,'Scopri i percorsi individuali'],
      'coach'=>['Se cerchi chiarezza, stai vivendo un cambiamento o devi prendere decisioni, puoi valutare il Mental Coaching del Metodo Yoganostress.',self::CO,'Scopri il Mental Coaching'],
      'reiki'=>['Se cerchi Reiki puoi valutare trattamenti individuali oppure, quando disponibili, corsi e attivazioni del Metodo Yoganostress.',self::R,'Scopri il Reiki'],
      'events'=>['Per ritiri, bagni sonori, workshop e approfondimenti consulta gli eventi Yoganostress. Se non c’è una data attiva, non presentiamo l’attività come disponibile.',self::E,'Vedi ritiri ed eventi'],
      'hatha'=>['Per schiena, cervicale, rigidità e postura, l’Hatha Yoga del Metodo Yoganostress è il percorso di gruppo più naturale da valutare. Comprende posture, corretto allineamento, respirazione, rilassamento, meditazione in movimento ed elementi di meditazione, con particolare attenzione alla colonna vertebrale.',self::H,'Scopri Hatha Yoga'],
      'stress'=>['Per stress e ansia la scelta dipende da come li vivi: Hatha Yoga se prevalgono tensione fisica e bisogno di movimento; Yoga Nidra se prevale il bisogno di fermarti e rilassarti; percorso individuale o Mental Coaching se il bisogno è più personale.',self::M,'Scopri il Metodo Yoganostress'],
      'wellbeing'=>['Se cerchi più benessere, equilibrio o energia ma non hai ancora un problema preciso, il Metodo Yoganostress può aiutarti a capire da dove partire tra Hatha Yoga, Yoga Nidra, percorsi individuali, Mental Coaching, Reiki ed esperienze di approfondimento.',self::M,'Scopri il Metodo Yoganostress'],
      'online'=>['Puoi seguire il Metodo Yoganostress anche online, con Hatha Yoga e Yoga Nidra secondo le modalità disponibili.',self::F,'Scopri la Prima Esperienza'],
      'schedule'=>['Per orari e prenotazioni parti dalla Prima Esperienza Yoganostress, dove trovi le modalità disponibili.',self::F,'Vedi la Prima Esperienza'],
      'fees'=>['Le quote dipendono dalla modalità e dalla frequenza. Parti dal Metodo Yoganostress per capire il percorso più adatto e arrivare poi alle informazioni aggiornate.',self::M,'Scopri il Metodo Yoganostress'],
      'noyoga'=>['Non devi necessariamente scegliere una lezione di yoga: in base a ciò che cerchi possiamo orientarti anche verso percorsi individuali, Mental Coaching, Reiki, ritiri o approfondimenti.',self::I,'Scopri i percorsi individuali'],
      'other'=>['Parti da ciò che stai vivendo: il Metodo Yoganostress ti aiuta a capire il percorso più adatto senza dover sapere già cosa scegliere.',self::M,'Scopri il Metodo Yoganostress']
    ];
    return $x[$c]??$x['other'];
  }

  static function limit_ok(){
    $ip=$_SERVER['REMOTE_ADDR']??'unknown'; $k='ynsba_'.substr(hash_hmac('sha256',$ip,wp_salt('nonce')),0,18); $n=(int)get_transient($k);
    if($n>=15) return false; set_transient($k,$n+1,5*MINUTE_IN_SECONDS); return true;
  }

  static function gap($q,$c){
    if($c!=='other') return; $t=mb_substr(self::n($q),0,80); $g=get_option(self::GAP,[]); if(!is_array($g))$g=[];
    if(!isset($g[$t]))$g[$t]=['hits'=>0,'first'=>time(),'last'=>time()]; $g[$t]['hits']++; $g[$t]['last']=time();
    uasort($g,function($a,$b){return $b['hits']<=>$a['hits'];}); update_option(self::GAP,array_slice($g,0,100,true),false);
  }

  static function rest(){
    register_rest_route('yoganostress/v1','/benessere',[
      'methods'=>'POST','permission_callback'=>'__return_true',
      'callback'=>function(WP_REST_Request $r){
        $q=trim(wp_strip_all_tags((string)$r->get_param('q')));
        if(mb_strlen($q)<2||mb_strlen($q)>400)return new WP_Error('yns_bad_query','Scrivi una domanda tra 2 e 400 caratteri.',['status'=>400]);
        if(!self::limit_ok())return new WP_Error('yns_rate','Troppe richieste ravvicinate. Riprova tra poco.',['status'=>429]);
        $c=self::cat($q); [$a,$u,$cta]=self::data($c); self::gap($q,$c);
        return rest_ensure_response(['answer'=>$a,'category'=>$c,'method_url'=>$u,'cta'=>$cta,'engine'=>'local-v0.5.3']);
      }
    ]);
  }

  static function box(){
    $needs=['Stress e ansia','Benessere ed equilibrio','Schiena e postura','Dormire meglio','Meditazione / Mindfulness'];
    $specific=['Percorso individuale','Mental Coaching','Reiki','Ritiri ed eventi'];
    $other=['Utilizzo / gestione sala','Collaborazioni professionali'];
    ob_start(); ?>
    <section class="ynsba" data-ynsba>
      <div class="ynsba__eyebrow">Metodo Yoganostress</div>
      <h2>Cosa stai cercando per il tuo benessere?</h2>
      <p class="ynsba__intro">Raccontaci brevemente se c’è qualcosa in particolare su cui vorresti essere accompagnato.</p>
      <form class="ynsba__form"><label>Scrivi qui la tua domanda</label><div class="ynsba__row"><input name="q" type="search" maxlength="400" placeholder="Es. Mi sento molto stressato e faccio fatica a rilassarmi…" autocomplete="off" required><button type="submit">Chiedi</button></div></form>
      <div class="ynsba__status" aria-live="polite"></div><div class="ynsba__answer" hidden></div>
      <details class="ynsba__more">
        <summary>Mostra opzioni rapide</summary>
        <div class="ynsba__suggestions">
          <p class="ynsba__hint">Oppure scegli uno degli argomenti più comuni:</p>
          <div class="ynsba__chips"><?php foreach($needs as $x): ?><button type="button" class="ynsba__chip ynsba__chip--need" data-query="<?php echo esc_attr($x); ?>"><?php echo esc_html($x); ?></button><?php endforeach; ?></div>
          <p class="ynsba__hint ynsba__hint--small">Cerchi già qualcosa di specifico?</p>
          <div class="ynsba__chips"><?php foreach($specific as $x): ?><button type="button" class="ynsba__chip" data-query="<?php echo esc_attr($x); ?>"><?php echo esc_html($x); ?></button><?php endforeach; ?></div>
          <details class="ynsba__other"><summary>Altre richieste</summary><div class="ynsba__chips ynsba__chips--other"><?php foreach($other as $x): ?><button type="button" class="ynsba__chip ynsba__chip--other" data-query="<?php echo esc_attr($x); ?>"><?php echo esc_html($x); ?></button><?php endforeach; ?></div></details>
        </div>
      </details>
    </section>
    <?php return ob_get_clean();
  }

  static function foot(){
    if(is_admin())return; $ep=esc_url_raw(rest_url('yoganostress/v1/benessere')); ?>
    <style>
    .yns-orientatore-benessere{display:none!important}
    .ynsba{--yns-bordeaux:#7b2749;--yns-porpora:#9b4b69;--yns-rose:#f3e3e9;--yns-cream:#fffaf7;--yns-text:#44343a;max-width:620px;margin:0 auto;padding:.95rem 1rem 1rem;background:linear-gradient(180deg,#fffafc 0%,#f8eef2 100%);color:var(--yns-text);font-family:inherit}
    .ynsba__eyebrow{font-size:.69rem;font-weight:800;letter-spacing:.09em;text-transform:uppercase;color:var(--yns-bordeaux)}
    .ynsba h2{margin:.22rem 0 .35rem;font-size:clamp(1.15rem,4vw,1.62rem);line-height:1.12;color:#4a3340}
    .ynsba__intro{margin:0 0 .68rem;line-height:1.4;font-size:.91rem;color:#65515a}
    .ynsba__form label{display:block;font-weight:700;margin:.25rem 0 .32rem;font-size:.88rem}
    .ynsba__row{display:grid;grid-template-columns:1fr auto;gap:.4rem}
    .ynsba input{min-width:0;border:1px solid #d8b9c6;background:#fff;color:inherit;border-radius:11px;padding:.72rem .8rem;font:inherit;font-size:.9rem}
    .ynsba input:focus{outline:none;border-color:var(--yns-porpora);box-shadow:0 0 0 3px rgba(123,39,73,.12)}
    .ynsba button[type=submit],.ynsba__cta{border:0;border-radius:11px;padding:.72rem .82rem;font:inherit;font-weight:800;cursor:pointer;text-decoration:none;background:var(--yns-bordeaux);color:#fff}
    .ynsba__status{min-height:.5em;margin:.34rem 0 0;font-size:.8rem;color:#79636d}
    .ynsba__answer{margin-top:.42rem;padding:.72rem;border:1px solid #e3cad4;border-radius:12px;background:#fff;line-height:1.45;font-size:.9rem}
    .ynsba__answer p{margin:0 0 .62rem}.ynsba__cta{display:inline-block}
    .ynsba__more{margin-top:.58rem;border-top:1px solid rgba(123,39,73,.14);padding-top:.52rem}
    .ynsba__more>summary,.ynsba__other>summary{cursor:pointer;font-weight:800;color:var(--yns-bordeaux);font-size:.84rem;list-style:none}
    .ynsba__more>summary::-webkit-details-marker,.ynsba__other>summary::-webkit-details-marker{display:none}
    .ynsba__more>summary:after{content:' +';font-weight:500}.ynsba__more[open]>summary:after{content:' −'}
    .ynsba__suggestions{margin-top:.5rem}.ynsba__hint{margin:.08rem 0 .35rem;font-size:.82rem;color:#715b65}.ynsba__hint--small{margin-top:.5rem}
    .ynsba__chips{display:flex;gap:.32rem;flex-wrap:wrap}.ynsba__chip{border:1px solid #d5adbd;background:#fff7fa;color:#654150;border-radius:999px;padding:.36rem .54rem;cursor:pointer;font:inherit;font-size:.77rem;line-height:1.15}.ynsba__chip--need{background:var(--yns-rose);border-color:#c98ea6}.ynsba__chip:hover{background:#edd6df}
    .ynsba__other{margin-top:.5rem}.ynsba__chips--other{margin-top:.38rem}
    .ynsba-float{position:fixed;right:12px;bottom:12px;z-index:99990;font-family:inherit}
    .ynsba-float__trigger{border:1px solid rgba(255,255,255,.58);border-radius:999px;padding:.57rem .76rem;font:inherit;font-size:.88rem;font-weight:800;cursor:pointer;background:linear-gradient(135deg,#722442,#963f62);color:#fff;box-shadow:0 8px 25px rgba(80,23,49,.25)}
    .ynsba-float__panel{position:absolute;right:0;bottom:calc(100% + 8px);width:min(88vw,630px);max-height:min(66vh,580px);overflow:auto;background:#fffafc;border:1px solid #d9b8c5;border-radius:18px;box-shadow:0 18px 54px rgba(70,27,47,.24)}
    .ynsba-float__top{position:sticky;top:0;z-index:30;display:flex;align-items:center;gap:.4rem;padding:.5rem .55rem;background:rgba(123,39,73,.97);color:#fff;border-radius:17px 17px 0 0;box-shadow:0 4px 14px rgba(70,27,47,.15)}
    .ynsba-float__brand{font-weight:800;font-size:.82rem;flex:1}
    .ynsba-float__mini,.ynsba-float__close{border:1px solid rgba(255,255,255,.45);background:rgba(255,255,255,.12);color:#fff;border-radius:999px;height:32px;cursor:pointer;font:inherit;font-weight:800;line-height:1}
    .ynsba-float__mini{padding:0 .62rem;font-size:.75rem}.ynsba-float__close{width:32px;font-size:1.22rem}
    .ynsba-float.is-minimized .ynsba{display:none}.ynsba-float.is-minimized .ynsba-float__panel{overflow:hidden;max-height:none;width:min(84vw,360px)}
    .ynsba-float.is-minimized .ynsba-float__top{border-radius:17px}
    [hidden]{display:none!important}
    @media(max-width:560px){
      .ynsba{padding:.72rem .76rem .8rem}.ynsba h2{font-size:1.12rem}.ynsba__intro{font-size:.84rem;margin-bottom:.55rem}.ynsba__form label{font-size:.82rem}.ynsba__row{grid-template-columns:1fr auto}.ynsba input{padding:.64rem .7rem;font-size:.84rem}.ynsba button[type=submit]{padding:.64rem .68rem;font-size:.82rem}.ynsba__answer{font-size:.84rem}
      .ynsba-float{right:8px;bottom:8px}.ynsba-float__trigger{max-width:68vw;padding:.52rem .68rem;font-size:.82rem}
      .ynsba-float__panel{position:fixed;left:8px;right:8px;bottom:54px;width:auto;max-height:56vh;border-radius:16px}
      .ynsba-float__top{border-radius:15px 15px 0 0}.ynsba-float.is-minimized .ynsba-float__panel{left:auto;right:8px;width:min(82vw,330px);bottom:54px}
    }
    </style>
    <div class="ynsba-float" data-ynsba-float><button type="button" class="ynsba-float__trigger" aria-expanded="false">Posso aiutarti?</button><div class="ynsba-float__panel" hidden><div class="ynsba-float__top"><span class="ynsba-float__brand">Yoganostress</span><button type="button" class="ynsba-float__mini" data-ynsba-mini>Riduci</button><button type="button" class="ynsba-float__close" data-ynsba-close aria-label="Chiudi assistente">×</button></div><?php echo self::box(); ?></div></div>
    <script>
    (()=>{const E=<?php echo wp_json_encode($ep); ?>;const render=(b,d)=>{b.replaceChildren();const p=document.createElement('p');p.textContent=d.answer;b.appendChild(p);const a=document.createElement('a');a.className='ynsba__cta';a.href=d.method_url||'#';a.textContent='Leggi la pagina consigliata →';a.addEventListener('click',()=>{const w=a.closest('[data-ynsba-float]');if(w){const p=w.querySelector('.ynsba-float__panel'),t=w.querySelector('.ynsba-float__trigger');if(p)p.hidden=true;if(t)t.setAttribute('aria-expanded','false')}});b.appendChild(a);b.hidden=false};document.querySelectorAll('[data-ynsba]').forEach(r=>{const f=r.querySelector('.ynsba__form'),i=r.querySelector('input[name=q]'),s=r.querySelector('.ynsba__status'),b=r.querySelector('.ynsba__answer');const ask=async q=>{s.textContent='Sto cercando nel Metodo Yoganostress…';b.hidden=true;try{const x=await fetch(E,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({q})});const d=await x.json();if(!x.ok)throw 0;render(b,d);s.textContent=''}catch(e){s.textContent='Non riesco a completare la ricerca in questo momento. Puoi comunque partire dal Metodo Yoganostress.'}};f.addEventListener('submit',e=>{e.preventDefault();const q=i.value.trim();if(q)ask(q)});r.querySelectorAll('[data-query]').forEach(x=>x.addEventListener('click',()=>{i.value=x.dataset.query;ask(x.dataset.query)}))});document.querySelectorAll('[data-ynsba-float]').forEach(w=>{const t=w.querySelector('.ynsba-float__trigger'),p=w.querySelector('.ynsba-float__panel'),mini=w.querySelector('[data-ynsba-mini]');const setOpen=o=>{p.hidden=!o;t.setAttribute('aria-expanded',o?'true':'false');if(!o){w.classList.remove('is-minimized');if(mini)mini.textContent='Riduci'}};t.addEventListener('click',e=>{e.preventDefault();setOpen(true)});w.addEventListener('click',e=>{const close=e.target.closest('[data-ynsba-close]');if(close){e.preventDefault();e.stopPropagation();setOpen(false);t.focus();return}const m=e.target.closest('[data-ynsba-mini]');if(m){e.preventDefault();e.stopPropagation();const on=w.classList.toggle('is-minimized');m.textContent=on?'Apri':'Riduci'}});document.addEventListener('keydown',e=>{if(e.key==='Escape'&&!p.hidden){setOpen(false);t.focus()}})})})();
    </script>
    <?php
  }
}
YNS_Benessere_V053::init();
