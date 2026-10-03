export const RS_PLAN_RULES=Object.freeze({
  pegaso:{name:"PEGASO",areas:4,questions:20,depth:"essential"},
  fenice:{name:"FENICE",areas:8,questions:60,depth:"deep"},
  andromeda:{name:"ANDROMEDA",areas:12,questions:150,depth:"maximum"}
});
export const RS_AREAS=Object.freeze([
  "Amore e relazioni","Lavoro e vocazione","Denaro e risorse","Energia e benessere quotidiano",
  "Famiglia e radici","Casa e appartenenza","Progetti e direzione","Comunicazione e relazioni sociali",
  "Creatività ed espressione","Crescita personale","Scelte e momenti di cambiamento","Mondo interiore"
]);
const SIGNS=["Ariete","Toro","Gemelli","Cancro","Leone","Vergine","Bilancia","Scorpione","Sagittario","Capricorno","Acquario","Pesci"];
const sign=x=>SIGNS[Math.floor((((x%360)+360)%360)/30)];
const fmt=x=>Number(x).toFixed(1)+"° "+sign(x);
function highlights(chart){
  const p=chart.planets||{};
  const h=[["Sole",p.Sun],["Luna",p.Moon],["Mercurio",p.Mercury],["Venere",p.Venus],["Marte",p.Mars]]
    .filter(x=>Number.isFinite(x[1])).map(x=>x[0]+" "+fmt(x[1]));
  if(chart.timeKnown&&chart.angles) h.push("Ascendente "+fmt(chart.angles.ascendant),"MC "+fmt(chart.angles.mc));
  return h;
}
function areaText(area,chart,answers,depth){
  const p=chart.planets||{}, a=answers||{};
  const base={
    "Amore e relazioni":`Venere ${fmt(p.Venus)} e Marte ${fmt(p.Mars)} descrivono il modo in cui cerchi scambio, vicinanza e iniziativa nelle relazioni.`,
    "Lavoro e vocazione":chart.timeKnown&&chart.angles?`Il MC ${fmt(chart.angles.mc)} aggiunge una direzione specifica alla lettura di vocazione e visibilità; Mercurio ${fmt(p.Mercury)} mostra come organizzi idee e decisioni.`:`Senza ora di nascita non attribuiamo un MC: la lettura professionale usa Mercurio ${fmt(p.Mercury)}, Sole ${fmt(p.Sun)} e gli altri dati certi.`,
    "Denaro e risorse":`Giove ${fmt(p.Jupiter)} e Saturno ${fmt(p.Saturn)} vengono letti insieme per distinguere espansione, misura e consolidamento delle risorse.`,
    "Energia e benessere quotidiano":`Sole ${fmt(p.Sun)} e Marte ${fmt(p.Mars)} indicano due registri diversi: continuità dell'energia e modo di attivarti.`,
    "Famiglia e radici":`La Luna ${fmt(p.Moon)} orienta la lettura dei bisogni emotivi e del senso di appartenenza, senza trasformarli in previsioni certe.`,
    "Casa e appartenenza":chart.timeKnown?`Con l'ora disponibile, l'Ascendente ${fmt(chart.angles.ascendant)} permette di contestualizzare meglio il modo in cui cerchi stabilità e spazio personale.`:`L'ora non è disponibile: case e Ascendente sono esclusi e la lettura resta sui fattori natali certi.`,
    "Progetti e direzione":`Giove ${fmt(p.Jupiter)}, Saturno ${fmt(p.Saturn)} e Marte ${fmt(p.Mars)} vengono integrati per leggere slancio, tempi e sostenibilità dei progetti.`,
    "Comunicazione e relazioni sociali":`Mercurio ${fmt(p.Mercury)} è il riferimento principale per il tuo stile di elaborazione e comunicazione; viene collegato al resto del quadro, non isolato.`,
    "Creatività ed espressione":`Sole ${fmt(p.Sun)} e Venere ${fmt(p.Venus)} offrono una chiave per osservare espressione personale, gusto e bisogno di riconoscimento.`,
    "Crescita personale":`Saturno ${fmt(p.Saturn)} e Giove ${fmt(p.Jupiter)} mostrano la tensione tra consolidare ciò che regge e ampliare ciò che può evolvere.`,
    "Scelte e momenti di cambiamento":`Urano ${fmt(p.Uranus)}, Nettuno ${fmt(p.Neptune)} e Plutone ${fmt(p.Pluto)} aggiungono il livello dei cicli più lenti, da leggere insieme ai pianeti personali.`,
    "Mondo interiore":`Luna ${fmt(p.Moon)}, Nettuno ${fmt(p.Neptune)} e Sole ${fmt(p.Sun)} costruiscono una lettura del mondo interiore che resta interpretativa e non fatalistica.`
  }[area];
  const personal=a[area]||a.focus||"";
  const add=personal?` Hai indicato come tema personale: “${String(personal).slice(0,220)}”. La lettura lo usa come contesto, senza forzare il cielo a confermare una risposta già decisa.`:"";
  const deeper=depth==="essential"?"":depth==="deep"?" Il significato viene collegato agli altri fattori del tema per evitare una lettura a compartimenti separati.":" Il quadro viene integrato con fattori personali, cicli lenti e finestre temporali, evidenziando convergenze e tensioni invece di ridurle a una sola etichetta.";
  return base+add+deeper;
}
export function buildPortrait({chart,plan="andromeda",profile={},answers={}}){
  const rule=RS_PLAN_RULES[plan]; if(!rule) throw new Error("Unsupported plan");
  const areas=RS_AREAS.slice(0,rule.areas);
  const birth=profile.birthPlace?` per la nascita a ${profile.birthPlace}`:"";
  const timeNote=chart.timeKnown?"L'ora di nascita è disponibile: Ascendente, MC e case possono entrare nella lettura.":"Ora di nascita non disponibile: Ascendente, MC e case non vengono inventati; gli elementi dipendenti dall'ora restano esclusi o dichiarati incerti.";
  return {
    product:"RITRATTO STELLARE",plan:rule.name,limits:{areas:rule.areas,questionsPerMonth:rule.questions},depth:rule.depth,
    title:`Il Ritratto Stellare di ${profile.name||"te"}`,
    introduction:`Questo Ritratto nasce dai dati astronomici personali${birth}. ${timeNote}`,
    astronomicalHighlights:highlights(chart),
    sections:areas.map(area=>({area,text:areaText(area,chart,answers,rule.depth)})),
    integrity:"Dato astronomico = calcolo reale. Dato mancante = dichiarato. Elemento incerto = segnalato o escluso. Mai inventato."
  };
}
