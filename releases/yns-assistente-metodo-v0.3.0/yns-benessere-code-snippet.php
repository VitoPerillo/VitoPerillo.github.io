if (!defined('ABSPATH')) { return; }
if (class_exists('YNS_Benessere_Assistant')) { return; }

final class YNS_Benessere_Assistant {
    const VER = '0.3.0';
    const METHOD_URL = 'https://www.yoganostress.it/metodo-yoganostress/';
    const PHONE = '+39 375 5741454';
    const GAP_OPT = 'yns_benessere_content_gaps';

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'rest']);
        add_action('wp_footer', [__CLASS__, 'render_floating'], 90);
        add_shortcode('yns_benessere', [__CLASS__, 'render_inline']);
    }

    public static function rest() {
        register_rest_route('yoganostress/v1', '/benessere', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'answer'],
            'permission_callback' => '__return_true',
            'args' => ['q' => ['required' => true, 'type' => 'string']],
        ]);
    }

    private static function normalize($s) {
        return mb_strtolower(remove_accents(wp_strip_all_tags((string)$s)));
    }

    private static function classify($q) {
        $s = self::normalize($q);
        $tests = [
            'sanitario' => ['ernia','diagnosi','l5','s1','sciatica','protrusione','operato','intervento','patologia','dolore forte','fase acuta','farmaco','medico','osteoporosi','artrosi','artrite','fibromialgia','scoliosi','ipertensione','gravidanza','frattura','discopatia'],
            'segreteria' => ['segreteria','appuntamento','colloquio','posso passare','posso venire senza','siete aperti','presentarmi'],
            'orari' => ['orario','orari','domani','oggi','quando posso','lezione','prima esperienza','prenot'],
            'sonno' => ['sonno','insonnia','dormire','risveglio','addorment'],
            'stress' => ['stress','ansia','agitazione','rimugin','preoccup','tensione mentale','rilassamento','rilassarmi'],
            'rigidita' => ['rigid','flessibil','elastico','principiante','mai fatto'],
            'schiena' => ['schiena','lombar','postura','spalle','colonna','cervicale'],
            'nidra_hatha' => ['nidra','hatha','quale pratica','differenza'],
            'quote' => ['prezzo','costo','quota','abbonamento','quanto costa'],
        ];
        foreach ($tests as $cat => $words) {
            foreach ($words as $word) if (mb_strpos($s, $word) !== false) return $cat;
        }
        return 'altro';
    }

    private static function local($cat) {
        switch ($cat) {
            case 'sanitario':
                return [0.98, 'Nel Metodo Yoganostress non diamo una lista di posizioni da fare in base a una diagnosi: non conta soltanto quale posizione viene eseguita, ma soprattutto come viene praticata e adattata alla persona. Anche in presenza di problemi lombari possiamo valutare se una pratica di Hatha Yoga del Metodo Yoganostress sia adatta alla tua situazione, ma prima della Prima Esperienza dobbiamo parlarne. La segreteria riceve esclusivamente su appuntamento: chiamaci prima al ' . self::PHONE . ' e concordiamo un breve colloquio oppure, sempre previo accordo, l\'arrivo circa 30 minuti prima della pratica. Non presentarti senza appuntamento o preavviso concordato. Se la situazione è acuta o presenta sintomi importanti, è opportuno anche confrontarti con il professionista sanitario che ti segue.'];
            case 'segreteria':
                return [0.99, 'La segreteria Yoganostress riceve esclusivamente su appuntamento: non presentarti in sede senza previo accordo. Chiamaci al ' . self::PHONE . ' per fissare il momento adatto. Prima della Prima Esperienza ti consigliamo di conoscere il Metodo Yoganostress.'];
            case 'orari':
                return [0.92, 'Possiamo orientarti verso l\'orario più adatto. Prima ti consigliamo di vedere come funziona il Metodo Yoganostress, così sai cosa aspettarti e perché il lavoro non è una semplice lezione di ginnastica. Dalla pagina del Metodo puoi poi prenotare la tua Prima Esperienza e verificare gli orari aggiornati.'];
            case 'sonno':
                return [0.90, 'Nel Metodo Yoganostress il sonno viene affrontato lavorando in modo integrato su corpo, respiro, mente ed emozioni. Yoga Nidra può avere un ruolo importante nel rilassamento profondo. Ti consigliamo di partire dal Metodo Yoganostress e, da lì, prenotare la Prima Esperienza.'];
            case 'stress':
                return [0.90, 'Il Metodo Yoganostress lavora sullo stress attraverso corpo, respiro, mente ed emozioni, con una pratica progressiva e guidata. Non serve essere flessibili né avere esperienza. Il primo passo è capire come funziona il Metodo Yoganostress; dalla stessa pagina puoi poi prenotare la tua Prima Esperienza.'];
            case 'rigidita':
                return [0.95, 'Nel Metodo Yoganostress non devi essere flessibile per iniziare. La pratica viene proposta in modo progressivo, senza competizione e rispettando il corpo della persona. Scopri prima il Metodo Yoganostress; dalla pagina del Metodo puoi poi prenotare la tua Prima Esperienza.'];
            case 'schiena':
                return [0.90, 'Nel Metodo Yoganostress il lavoro sulla schiena e sulla postura non consiste nel dare una posizione uguale per tutti: conta come il movimento viene eseguito, adattato e collegato a respiro e consapevolezza. Se hai una condizione specifica o dolore importante, parlane con noi prima della Prima Esperienza. Ti consigliamo di partire dalla pagina del Metodo Yoganostress.'];
            case 'nidra_hatha':
                return [0.90, 'Hatha Yoga e Yoga Nidra fanno parte del Metodo Yoganostress e lavorano in modo diverso ma complementare. La scelta dipende da ciò che stai vivendo e dall\'obiettivo del momento. Parti dalla pagina del Metodo Yoganostress: da lì puoi approfondire e prenotare la Prima Esperienza.'];
            case 'quote':
                return [0.78, 'Le quote dipendono dalla modalità e dalla frequenza scelta. Prima ti consigliamo di conoscere il Metodo Yoganostress; dalla pagina del Metodo puoi poi arrivare alla Prima Esperienza e alle informazioni aggiornate.'];
            default:
                return [0.30, 'La tua domanda è utile e vogliamo risponderti in modo coerente con il Metodo Yoganostress, senza inventare informazioni. Ti consigliamo intanto di partire dalla pagina del Metodo Yoganostress; questa richiesta viene anche considerata come possibile argomento da sviluppare nei contenuti del sito.'];
        }
    }

    private static function rate_ok() {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
        $key = 'ynsba_' . substr(hash_hmac('sha256', $ip, wp_salt('nonce')), 0, 18);
        $n = (int) get_transient($key);
        if ($n >= 15) return false;
        set_transient($key, $n + 1, 5 * MINUTE_IN_SECONDS);
        return true;
    }

    private static function approved_sources($q) {
        $ids = array_values(array_filter([
            (int) get_option('page_on_front'),
            (int) url_to_postid(self::METHOD_URL),
        ]));
        $tokens = array_values(array_filter(array_unique(preg_split('/[^a-z0-9à-ÿ]+/iu', self::normalize($q))), fn($x) => mb_strlen($x) >= 4));
        if (!$ids || !$tokens) return [];
        $out = [];
        foreach ($ids as $id) {
            $p = get_post($id);
            if (!$p || 'publish' !== $p->post_status) continue;
            $hay = self::normalize($p->post_title . ' ' . $p->post_content);
            $score = 0;
            foreach ($tokens as $t) if (mb_strpos($hay, $t) !== false) $score++;
            if ($score) $out[] = ['score'=>$score,'title'=>get_the_title($id),'url'=>get_permalink($id)];
        }
        usort($out, fn($a,$b) => $b['score'] <=> $a['score']);
        return array_map(fn($x)=>['title'=>$x['title'],'url'=>$x['url']], array_slice($out,0,2));
    }

    private static function log_gap($q, $cat, $coverage) {
        if ($cat === 'sanitario' || $coverage >= 0.55) return;
        $topic = $cat === 'altro' ? mb_substr(self::normalize($q), 0, 80) : $cat;
        $gaps = get_option(self::GAP_OPT, []);
        if (!is_array($gaps)) $gaps = [];
        if (!isset($gaps[$topic])) $gaps[$topic] = ['category'=>$cat,'hits'=>0,'first'=>time(),'last'=>time()];
        $gaps[$topic]['hits']++;
        $gaps[$topic]['last'] = time();
        uasort($gaps, fn($a,$b) => ($b['hits'] <=> $a['hits']));
        update_option(self::GAP_OPT, array_slice($gaps, 0, 100, true), false);
    }

    public static function answer(WP_REST_Request $r) {
        $q = trim(wp_strip_all_tags((string)$r->get_param('q')));
        if (mb_strlen($q) < 2 || mb_strlen($q) > 400) return new WP_Error('yns_bad_query', 'Scrivi una domanda tra 2 e 400 caratteri.', ['status'=>400]);
        if (!self::rate_ok()) return new WP_Error('yns_rate', 'Troppe richieste ravvicinate. Riprova tra poco.', ['status'=>429]);
        $cat = self::classify($q);
        [$coverage, $answer] = self::local($cat);
        $sources = self::approved_sources($q);
        self::log_gap($q, $cat, $coverage);
        return rest_ensure_response([
            'answer'=>$answer,
            'category'=>$cat,
            'coverage'=>$coverage,
            'sources'=>$sources,
            'method_url'=>self::METHOD_URL,
            'cta'=>'Scopri il Metodo Yoganostress',
            'cta_note'=>'Da lì puoi prenotare la tua Prima Esperienza.',
            'engine'=>'local',
        ]);
    }

    private static function widget_markup($floating = false) {
        $chips = ['Stress e tensione','Dormire meglio','Schiena e rigidità','Rilassamento','Sono principiante','Orari e lezioni','Costi'];
        ob_start(); ?>
        <section class="ynsba" data-ynsba>
            <div class="ynsba__eyebrow">Metodo Yoganostress</div>
            <h2>Cosa stai cercando per il tuo benessere?</h2>
            <p class="ynsba__intro">Raccontaci brevemente se c’è qualcosa in particolare su cui vorresti essere accompagnato.</p>
            <div class="ynsba__chips" aria-label="Argomenti suggeriti">
                <?php foreach ($chips as $chip): ?><button type="button" class="ynsba__chip" data-query="<?php echo esc_attr($chip); ?>"><?php echo esc_html($chip); ?></button><?php endforeach; ?>
            </div>
            <form class="ynsba__form">
                <label>Scrivi qui la tua domanda</label>
                <div class="ynsba__row"><input name="q" type="search" maxlength="400" placeholder="Es. Dormo male e mi sento molto teso: da dove posso iniziare?" autocomplete="off" required><button type="submit">Chiedi</button></div>
            </form>
            <div class="ynsba__status" aria-live="polite"></div>
            <div class="ynsba__answer" hidden></div>
        </section>
        <?php return ob_get_clean();
    }

    public static function render_inline() { return self::widget_markup(false); }

    public static function render_floating() {
        if (is_admin()) return;
        $endpoint = esc_url_raw(rest_url('yoganostress/v1/benessere'));
        $method = esc_url_raw(self::METHOD_URL);
        ?>
        <style>
        .ynsba{max-width:760px;margin:0 auto;padding:clamp(1rem,4vw,2rem);border:1px solid rgba(0,0,0,.10);border-radius:22px;background:#fff;color:#1f2937;box-shadow:0 14px 40px rgba(0,0,0,.08);font-family:inherit}.ynsba__eyebrow{font-size:.78rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;opacity:.68}.ynsba h2{margin:.3rem 0 .5rem;font-size:clamp(1.5rem,5vw,2.2rem);line-height:1.08}.ynsba__intro{margin:0 0 1rem;line-height:1.55}.ynsba__chips{display:flex;gap:.5rem;overflow-x:auto;padding:.25rem 0 .8rem}.ynsba__chip{white-space:nowrap;border:1px solid currentColor;background:transparent;border-radius:999px;padding:.55rem .8rem;cursor:pointer;font:inherit}.ynsba__form label{display:block;font-weight:700;margin:.5rem 0}.ynsba__row{display:grid;grid-template-columns:1fr auto;gap:.5rem}.ynsba input{min-width:0;border:1px solid rgba(0,0,0,.22);border-radius:12px;padding:.85rem 1rem;font:inherit}.ynsba button[type=submit],.ynsba__cta{border:0;border-radius:12px;padding:.85rem 1.05rem;font:inherit;font-weight:700;cursor:pointer;text-decoration:none;background:#1f2937;color:#fff}.ynsba__status{min-height:1.4em;margin:.75rem 0 0;opacity:.75}.ynsba__answer{margin-top:.75rem;padding:1rem;border-radius:14px;background:rgba(0,0,0,.035);line-height:1.58}.ynsba__answer p{margin:0 0 1rem}.ynsba__cta{display:inline-block;margin:.25rem 0 .35rem}.ynsba__answer small{display:block;opacity:.75}.ynsba__sources{display:grid;gap:.35rem;margin:0 0 1rem}.ynsba__sources a{text-decoration:underline}.ynsba-float{position:fixed;right:18px;bottom:18px;z-index:99990}.ynsba-float__trigger{border:0;border-radius:999px;padding:.85rem 1rem;font:inherit;font-weight:700;cursor:pointer;background:#1f2937;color:#fff;box-shadow:0 10px 30px rgba(0,0,0,.22)}.ynsba-float__panel{position:absolute;right:0;bottom:calc(100% + 10px);width:min(92vw,760px);max-height:min(82vh,760px);overflow:auto;background:#fff;border-radius:22px;box-shadow:0 20px 60px rgba(0,0,0,.25);padding:.25rem}.ynsba-float__close{position:absolute;right:10px;top:8px;z-index:2;width:36px;height:36px;border:0;border-radius:50%;background:rgba(0,0,0,.07);font-size:1.4rem;cursor:pointer}@media(max-width:560px){.ynsba__row{grid-template-columns:1fr}.ynsba button[type=submit]{width:100%}.ynsba-float{right:10px;bottom:10px;left:10px}.ynsba-float__trigger{width:100%}.ynsba-float__panel{position:fixed;left:10px;right:10px;bottom:64px;width:auto;max-height:78vh}}
        </style>
        <div class="ynsba-float" data-ynsba-float><button type="button" class="ynsba-float__trigger" aria-expanded="false">Benessere: chiedi a Yoganostress</button><div class="ynsba-float__panel" hidden><button type="button" class="ynsba-float__close" aria-label="Chiudi">×</button><?php echo self::widget_markup(true); ?></div></div>
        <script>
        (()=>{const ENDPOINT=<?php echo wp_json_encode($endpoint); ?>,METHOD=<?php echo wp_json_encode($method); ?>;const norm=s=>String(s||'').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,'');const has=(s,w)=>w.some(x=>s.includes(x));const classify=q=>{const s=norm(q),tests=[['sanitario',['ernia','diagnosi','l5','s1','sciatica','protrusione','operato','intervento','patologia','dolore forte','fase acuta','farmaco','medico','osteoporosi','artrosi','artrite','fibromialgia','scoliosi','ipertensione','gravidanza','frattura','discopatia']],['segreteria',['segreteria','appuntamento','colloquio','posso passare','siete aperti','presentarmi']],['orari',['orario','orari','domani','oggi','quando posso','lezione','prima esperienza','prenot']],['sonno',['sonno','insonnia','dormire','risveglio','addorment']],['stress',['stress','ansia','agitazione','rimugin','preoccup','tensione mentale','rilassamento','rilassarmi']],['rigidita',['rigid','flessibil','elastico','principiante','mai fatto']],['schiena',['schiena','lombar','postura','spalle','colonna','cervicale']],['nidra_hatha',['nidra','hatha','quale pratica','differenza']],['quote',['prezzo','costo','quota','abbonamento','quanto costa']]];for(const [c,w] of tests)if(has(s,w))return c;return'altro'};const fallback=q=>{const c=classify(q),a={sanitario:'Nel Metodo Yoganostress non diamo una lista di posizioni da fare in base a una diagnosi: conta soprattutto come la pratica viene eseguita e adattata alla persona. Prima della Prima Esperienza dobbiamo parlarne. La segreteria riceve solo su appuntamento: chiamaci prima e concordiamo un colloquio. Non presentarti senza previo accordo. Se la situazione è acuta o presenta sintomi importanti, confrontati anche con il professionista sanitario che ti segue.',segreteria:'La segreteria Yoganostress riceve esclusivamente su appuntamento. Non presentarti in sede senza previo accordo. Prima della Prima Esperienza ti consigliamo di conoscere il Metodo Yoganostress.',orari:'Possiamo orientarti verso l’orario più adatto. Prima ti consigliamo di vedere come funziona il Metodo Yoganostress; dalla pagina del Metodo puoi poi prenotare la tua Prima Esperienza e verificare gli orari aggiornati.',sonno:'Nel Metodo Yoganostress il sonno viene affrontato lavorando in modo integrato su corpo, respiro, mente ed emozioni. Yoga Nidra può avere un ruolo importante nel rilassamento profondo. Parti dal Metodo Yoganostress.',stress:'Il Metodo Yoganostress lavora sullo stress attraverso corpo, respiro, mente ed emozioni, con una pratica progressiva e guidata. Scopri prima il Metodo Yoganostress.',rigidita:'Nel Metodo Yoganostress non devi essere flessibile per iniziare. La pratica viene proposta in modo progressivo e rispettoso della persona. Scopri il Metodo Yoganostress.',schiena:'Nel Metodo Yoganostress il lavoro sulla schiena non consiste nel dare una posizione uguale per tutti: conta come il movimento viene eseguito e adattato alla persona. Se hai una condizione specifica o dolore importante, parlane con noi prima della Prima Esperienza.',nidra_hatha:'Hatha Yoga e Yoga Nidra fanno parte del Metodo Yoganostress e lavorano in modo diverso ma complementare. Parti dalla pagina del Metodo Yoganostress.',quote:'Le quote dipendono dalla modalità e dalla frequenza scelta. Prima ti consigliamo di conoscere il Metodo Yoganostress.',altro:'Vogliamo risponderti in modo coerente con il Metodo Yoganostress senza inventare informazioni. Intanto parti dalla pagina del Metodo.'};return a[c]||a.altro};const render=(box,d)=>{box.replaceChildren();const p=document.createElement('p');p.textContent=d.answer;box.appendChild(p);if(Array.isArray(d.sources)&&d.sources.length){const w=document.createElement('div');w.className='ynsba__sources';const b=document.createElement('strong');b.textContent='Approfondimenti dal sito:';w.appendChild(b);d.sources.forEach(s=>{const a=document.createElement('a');a.href=s.url;a.textContent=s.title;w.appendChild(a)});box.appendChild(w)}const a=document.createElement('a');a.className='ynsba__cta';a.href=d.method_url||METHOD;a.textContent=d.cta||'Scopri il Metodo Yoganostress';box.appendChild(a);const sm=document.createElement('small');sm.textContent=d.cta_note||'Da lì puoi prenotare la tua Prima Esperienza.';box.appendChild(sm);box.hidden=false};document.querySelectorAll('[data-ynsba]').forEach(root=>{const f=root.querySelector('.ynsba__form'),i=root.querySelector('input[name=q]'),st=root.querySelector('.ynsba__status'),box=root.querySelector('.ynsba__answer');const ask=async q=>{st.textContent='Sto cercando nel Metodo Yoganostress…';box.hidden=true;const c=new AbortController(),to=setTimeout(()=>c.abort(),3200);try{const r=await fetch(ENDPOINT,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({q}),signal:c.signal});const d=await r.json();if(!r.ok)throw 0;render(box,d);st.textContent=''}catch(e){render(box,{answer:fallback(q),method_url:METHOD});st.textContent='Risposta essenziale disponibile anche senza ricerca avanzata.'}finally{clearTimeout(to)}};f.addEventListener('submit',e=>{e.preventDefault();const q=i.value.trim();if(q)ask(q)});root.querySelectorAll('[data-query]').forEach(b=>b.addEventListener('click',()=>{i.value=b.dataset.query;ask(b.dataset.query)}))});document.querySelectorAll('[data-ynsba-float]').forEach(w=>{const t=w.querySelector('.ynsba-float__trigger'),p=w.querySelector('.ynsba-float__panel'),x=w.querySelector('.ynsba-float__close'),open=o=>{p.hidden=!o;t.setAttribute('aria-expanded',o?'true':'false');if(o)setTimeout(()=>p.querySelector('input[name=q]')?.focus(),30)};t.addEventListener('click',()=>open(p.hidden));x.addEventListener('click',()=>open(false));document.addEventListener('keydown',e=>{if(e.key==='Escape'&&!p.hidden)open(false)})})})();
        </script>
        <?php
    }
}
YNS_Benessere_Assistant::init();