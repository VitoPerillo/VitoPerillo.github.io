if (!defined('ABSPATH')) { return; }

final class YNS_Benessere_V051 {
  const M='https://www.yoganostress.it/metodo-yoganostress/';
  const F='https://www.yoganostress.it/benvenuto-alla-tua-prima-esperienza-yoganostress/';
  const I='https://www.yoganostress.it/sessioni-individuali-yoganostress/';
  const E='https://www.yoganostress.it/percorsi-eventi-roma/';
  const H='https://www.yoganostress.it/corsi-di-hatha-yoga-scegli-il-metodo-yoganostress/';
  const N='https://www.yoganostress.it/benefici-della-pratica-di-yoga-nidra/';
  const S='https://www.yoganostress.it/affitto-sala-yoga-a-roma-per-corsi-eventi-olistici/';
  const C='https://www.yoganostress.it/collaborazioni/';
  const GAP='yns_benessere_content_gaps';

  static function init(){ add_action('rest_api_init',[__CLASS__,'rest']); add_action('wp_footer',[__CLASS__,'foot'],90); add_shortcode('yns_benessere',[__CLASS__,'box']); }
  static function n($s){ return mb_strtolower(remove_accents(wp_strip_all_tags((string)$s))); }

  static function cat($q){
    $s=self::n($q);
    $m=[
      'health'=>['ernia','diagnosi','l5','s1','sciatica','protrusione','patologia','dolore forte','fase acuta','osteoporosi','artrosi','artrite','fibromialgia','scoliosi','frattura','discopatia'],
      'sala'=>['affitto sala','affittare sala','utilizzo sala','uso sala','gestione sala','cerco una sala','spazio per evento','spazio per corso','sala yoga'],
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
      'coach'=>['Se cerchi chiarezza, stai vivendo un cambiamento o devi prendere decisioni, puoi valutare il Mental Coaching del Metodo Yoganostress.',self::I,'Scopri il Mental Coaching'],
      'reiki'=>['Se cerchi Reiki puoi valutare trattamenti individuali oppure, quando disponibili, corsi e attivazioni del Metodo Yoganostress.',self::I,'Scopri il Reiki'],
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
        return rest_ensure_response(['answer'=>$a,'category'=>$c,'method_url'=>$u,'cta'=>$cta,'engine'=>'local-v0.5.1']);
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
      <div class="ynsba__suggestions">
        <p class="ynsba__hint">Oppure scegli uno degli argomenti più comuni:</p>
        <div class="ynsba__chips"><?php foreach($needs as $x): ?><button type="button" class="ynsba__chip ynsba__chip--need" data-query="<?php echo esc_attr($x); ?>"><?php echo esc_html($x); ?></button><?php endforeach; ?></div>
        <p class="ynsba__hint ynsba__hint--small">Cerchi già qualcosa di specifico?</p>
        <div class="ynsba__chips"><?php foreach($specific as $x): ?><button type="button" class="ynsba__chip" data-query="<?php echo esc_attr($x); ?>"><?php echo esc_html($x); ?></button><?php endforeach; ?></div>
        <details class="ynsba__other"><summary>Altre richieste</summary><div class="ynsba__chips ynsba__chips--other"><?php foreach($other as $x): ?><button type="button" class="ynsba__chip ynsba__chip--other" data-query="<?php echo esc_attr($x); ?>"><?php echo esc_html($x); ?></button><?php endforeach; ?></div></details>
      </div>
    </section>
    <?php return ob_get_clean();
  }

  static function foot(){
    if(is_admin())return; $ep=esc_url_raw(rest_url('yoganostress/v1/benessere')); ?>
    <style>
    .ynsba{max-width:660px;margin:0 auto;padding:clamp(.9rem,3vw,1.35rem);border:1px solid #ded5c7;border-radius:18px;background:linear-gradient(180deg,#fbfaf7 0%,#f5f1e8 100%);color:#413a31;box-shadow:0 10px 34px rgba(74,61,42,.12);font-family:inherit}.ynsba__eyebrow{font-size:.72rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#7b674d}.ynsba h2{margin:.28rem 2.4rem .4rem 0;font-size:clamp(1.28rem,4.3vw,1.85rem);line-height:1.12}.ynsba__intro{margin:0 0 .8rem;line-height:1.45;font-size:.96rem}.ynsba__form label{display:block;font-weight:700;margin:.35rem 0}.ynsba__row{display:grid;grid-template-columns:1fr auto;gap:.45rem}.ynsba input{min-width:0;border:1px solid #c9bcaa;background:rgba(255,255,255,.9);color:inherit;border-radius:11px;padding:.78rem .9rem;font:inherit}.ynsba input:focus{outline:none;border-color:#8c7657;box-shadow:0 0 0 3px rgba(140,118,87,.13)}.ynsba button[type=submit],.ynsba__cta{border:0;border-radius:11px;padding:.78rem .95rem;font:inherit;font-weight:700;cursor:pointer;text-decoration:none;background:#7b674d;color:#fff}.ynsba__status{min-height:.8em;margin:.4rem 0 0;font-size:.84rem;color:#716557}.ynsba__answer{margin-top:.5rem;padding:.8rem;border:1px solid #e0d7ca;border-radius:12px;background:rgba(255,255,255,.74);line-height:1.5}.ynsba__answer p{margin:0 0 .7rem}.ynsba__cta{display:inline-block}.ynsba__suggestions{margin-top:.65rem;border-top:1px solid rgba(123,103,77,.18);padding-top:.55rem}.ynsba__hint{margin:.1rem 0 .4rem;font-size:.87rem;color:#655a4c}.ynsba__hint--small{margin-top:.55rem}.ynsba__chips{display:flex;gap:.35rem;flex-wrap:wrap}.ynsba__chip{border:1px solid #cdbfae;background:#fffaf3;color:#56493a;border-radius:999px;padding:.4rem .6rem;cursor:pointer;font:inherit;font-size:.82rem;line-height:1.15}.ynsba__chip--need{background:#efe7d8;border-color:#c8b394}.ynsba__chip:hover{background:#e8ddcb}.ynsba__other{margin-top:.55rem;font-size:.85rem}.ynsba__other summary{cursor:pointer;color:#75654f;font-weight:700}.ynsba__chips--other{margin-top:.4rem}.ynsba-float{position:fixed;right:14px;bottom:14px;z-index:99990}.ynsba-float__trigger{border:1px solid rgba(255,255,255,.45);border-radius:999px;padding:.66rem .84rem;font:inherit;font-weight:700;cursor:pointer;background:#7b674d;color:#fff;box-shadow:0 8px 24px rgba(62,49,32,.22)}.ynsba-float__panel{position:absolute;right:0;bottom:calc(100% + 9px);width:min(90vw,680px);max-height:min(72vh,650px);overflow:auto;background:#f8f5ef;border:1px solid #d9cebf;border-radius:20px;box-shadow:0 18px 52px rgba(50,39,25,.22);padding:.2rem}.ynsba-float__close{position:absolute;right:8px;top:8px;z-index:5;width:34px;height:34px;border:1px solid #d5c7b7;border-radius:50%;background:#fffaf3;color:#594b3b;font-size:1.35rem;line-height:1;cursor:pointer}
    @media(max-width:560px){.ynsba{padding:.8rem}.ynsba h2{font-size:1.25rem;margin-right:2.2rem}.ynsba__intro{font-size:.9rem}.ynsba__row{grid-template-columns:1fr auto}.ynsba button[type=submit]{padding:.7rem .75rem}.ynsba__chip{font-size:.78rem;padding:.36rem .5rem}.ynsba-float{right:10px;bottom:10px}.ynsba-float__trigger{width:auto;max-width:72vw;padding:.6rem .76rem}.ynsba-float__panel{position:fixed;left:10px;right:10px;bottom:60px;width:auto;max-height:68vh}.ynsba-float__close{position:sticky;float:right;top:6px;margin:4px 4px -38px 0}}
    </style>
    <div class="ynsba-float" data-ynsba-float><button type="button" class="ynsba-float__trigger" aria-expanded="false">Posso aiutarti?</button><div class="ynsba-float__panel" hidden><button type="button" class="ynsba-float__close" aria-label="Chiudi">×</button><?php echo self::box(); ?></div></div>
    <script>
    (()=>{const E=<?php echo wp_json_encode($ep); ?>;const render=(b,d)=>{b.replaceChildren();const p=document.createElement('p');p.textContent=d.answer;b.appendChild(p);const a=document.createElement('a');a.className='ynsba__cta';a.href=d.method_url||'#';a.textContent=d.cta||'Scopri il Metodo Yoganostress';b.appendChild(a);b.hidden=false};document.querySelectorAll('[data-ynsba]').forEach(r=>{const f=r.querySelector('.ynsba__form'),i=r.querySelector('input[name=q]'),s=r.querySelector('.ynsba__status'),b=r.querySelector('.ynsba__answer');const ask=async q=>{s.textContent='Sto cercando nel Metodo Yoganostress…';b.hidden=true;try{const x=await fetch(E,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({q})});const d=await x.json();if(!x.ok)throw 0;render(b,d);s.textContent=''}catch(e){s.textContent='Non riesco a completare la ricerca in questo momento. Puoi comunque partire dal Metodo Yoganostress.'}};f.addEventListener('submit',e=>{e.preventDefault();const q=i.value.trim();if(q)ask(q)});r.querySelectorAll('[data-query]').forEach(x=>x.addEventListener('click',()=>{i.value=x.dataset.query;ask(x.dataset.query)}))});document.querySelectorAll('[data-ynsba-float]').forEach(w=>{const t=w.querySelector('.ynsba-float__trigger'),p=w.querySelector('.ynsba-float__panel'),x=w.querySelector('.ynsba-float__close'),open=o=>{p.hidden=!o;t.setAttribute('aria-expanded',o?'true':'false')};t.addEventListener('click',()=>open(p.hidden));x.addEventListener('click',()=>open(false));document.addEventListener('keydown',e=>{if(e.key==='Escape')open(false)})})})();
    </script>
    <?php
  }
}
YNS_Benessere_V051::init();
