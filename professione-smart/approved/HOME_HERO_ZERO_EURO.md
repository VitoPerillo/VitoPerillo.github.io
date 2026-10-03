# Professione Smart — Home Hero “0 €” (APPROVATO)

Stato: approvato dall'utente il 03/10/2026.
Destinazione: HOME / landing iniziale.
Branch di lavorazione: professione-smart-staging.

## Vincoli
- La foto beauty/parrucchiera approvata resta invariata.
- CTA, testi restanti, struttura e flusso restano invariati.
- Non mostrare pubblicamente lo 0,5% interno di Professione Smart.
- Non applicare questa patch a una baseline storica/stale: prima acquisire il sorgente canonico del Worker LIVE.

## Markup da inserire nella hero
```html
<div class="hero-price" aria-label="Zero euro di canone mensile">
  <div class="hero-zero">0 €</div>
  <div class="hero-zero-sub">di canone mensile</div>
</div>
```

## CSS approvato
```css
.hero-price{
  margin:8px 0 22px;
  line-height:1;
}
.hero-zero{
  font-size:clamp(64px,9vw,112px);
  font-weight:950;
  letter-spacing:-5px;
  color:#fff;
}
.hero-zero-sub{
  margin-top:7px;
  font-size:clamp(15px,2vw,22px);
  font-weight:850;
  letter-spacing:.6px;
  text-transform:uppercase;
  color:#dbe4f0;
}
@media(max-width:760px){
  .hero-zero{font-size:72px;letter-spacing:-3px}
  .hero-zero-sub{font-size:14px}
}
```

## Gate live
La modifica è considerata LIVE solo dopo:
1. readback del Worker Cloudflare corrente;
2. applicazione sulla baseline canonica;
3. Preview Cloudflare;
4. promozione approvata;
5. verifica HTTP/visuale della home LIVE.
