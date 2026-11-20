import { riskGate, valueGate, factGate } from "../core/gates.js";
import { editorialDraftGate } from "../core/article.js";
import { sha256 } from "../core/utils.js";

// Vetted original editorial item. It is not a generic bypass for UGC,
// feeds, news scraping or unsupervised rewriting.
export const reviewedStoryNov21 = Object.freeze({
  slug: "villa-pamphilj-non-puoi-capire-22-novembre-2026",
  source_url: "https://teatriincomune.roma.it/events/non-puoi-capire/",
  area_id: 12,
  category_id: 5,
  event_start: "2026-11-22T11:30:00+01:00",
  verifiedSource: {
    title: "Non puoi capire... al Teatro Villa Pamphilj, 22 novembre 2026",
    text: [
      "Il Teatro Villa Pamphilj ospita lo spettacolo Non puoi capire... domenica 22 novembre 2026 alle ore 11:30. È uno spettacolo della rassegna Comedy presentato da Scenaria ETS, con Lara Ceccarelli e Roberto Tufo.",
      "La proposta teatrale è basata sull'improvvisazione. Il pubblico racconta aneddoti e offre spunti agli interpreti, che li rielaborano in scene create sul momento senza un copione prestabilito.",
      "Il Teatro Villa Pamphilj si trova nel Parco Villa Doria Pamphilj a Roma, con ingresso da via di San Pancrazio 10 e da piazza Porta San Pancrazio 9/a.",
      "Secondo il calendario e la scheda ufficiale pubblicata da Teatri in Comune, l'ingresso costa 10 euro per il biglietto intero e 7 euro per il ridotto. Per informazioni e prenotazioni il sito ufficiale rimanda alla segreteria del teatro."
    ].join("\n\n"),
  },
  editorDraft: {
    headline: "Villa Pamphilj, il 22 novembre teatro d'improvvisazione con «Non puoi capire…»",
    summary: "Domenica alle 11:30 Lara Ceccarelli e Roberto Tufo portano al Teatro Villa Pamphilj uno spettacolo costruito anche con gli aneddoti degli spettatori.",
    body: [
      "Domenica 22 novembre 2026, alle 11:30, il Teatro Villa Pamphilj ospita «Non puoi capire…», uno spettacolo di improvvisazione proposto da Scenaria ETS con Lara Ceccarelli e Roberto Tufo. L'appuntamento rientra nel programma di teatro e comicità dello spazio culturale.",
      "La particolarità dell'iniziativa è il ruolo del pubblico. Invece di seguire una storia interamente definita prima della rappresentazione, gli interpreti raccolgono piccoli racconti ed episodi suggeriti dagli spettatori e li trasformano in nuove scene sul palco. Chi assiste contribuisce così agli spunti da cui nasce lo spettacolo.",
      "La sede è il Teatro Villa Pamphilj, nel Parco Villa Doria Pamphilj a Roma. Tra gli accessi indicati figurano via di San Pancrazio 10 e piazza Porta San Pancrazio 9/a. La data e l'orario pubblicati dal teatro sono domenica 22 novembre alle 11:30.",
      "Secondo la scheda ufficiale, il biglietto intero costa 10 euro e il ridotto 7 euro. Prima di organizzare la visita è opportuno consultare direttamente la pagina di Teatri in Comune per verificare le informazioni più aggiornate su prenotazioni e disponibilità. La comunicazione ufficiale resta il riferimento per eventuali variazioni."
    ].join("\n\n"),
  }
});

export function validateVerifiedLocalStory(story = reviewedStoryNov21) {
  const reasons = [];
  if (story.source_url !== "https://teatriincomune.roma.it/events/non-puoi-capire/")
    reasons.push("source_url_not_approved");
  if (story.area_id !== 12 || story.category_id !== 5)
    reasons.push("local_classification_mismatch");
  if (riskGate({title:story.verifiedSource.title,text:story.verifiedSource.text},95).level!=="GREEN" ||
      riskGate({title:story.editorDraft.headline,text:story.editorDraft.body},95).level!=="GREEN")
    reasons.push("risk_not_green");
  const value = valueGate({title:story.verifiedSource.title,text:story.verifiedSource.text,area_id:story.area_id,original_date:story.event_start});
  if(!value.pass) reasons.push("no_local_utility");
  const originality = editorialDraftGate(story.verifiedSource.text, story.editorDraft);
  if(!originality.pass) reasons.push(...originality.violations);
  const facts = factGate(story.verifiedSource,story.editorDraft);
  if(!facts.pass) reasons.push(...facts.violations);
  return {pass:reasons.length===0,reasons,words:originality.words};
}

export async function publishVerifiedLocalStory(db, now = new Date(), story = reviewedStoryNov21) {
  // Do not present an expired event as an upcoming story if a deployment was delayed.
  if (now.getTime() > Date.parse(story.event_start) + 3600000)
    return { status: "expired_without_publishing" };
  const gate = validateVerifiedLocalStory(story);
  if (!gate.pass) return { status: "held", reasons: gate.reasons };
  const {slug,source_url,area_id,category_id,editorDraft,event_start}=story;
  const duplicate = await db.prepare(
    "SELECT id FROM la_content WHERE (slug=? OR source_url=? OR lower(title) LIKE '%non puoi capire%') " +
    "AND content_type IN ('news','event') AND status IN ('published','updated') LIMIT 1"
  ).bind(slug,source_url).first();
  if(duplicate) return { status: "duplicate", content_id: Number(duplicate.id) };
  const sourceHash=await sha256(source_url+"|"+editorDraft.headline);
  const ins=await db.prepare(
    "INSERT INTO la_content(content_type,slug,title,summary,body,area_id,category_id,source_url,status,published_at,updated_at,valid_from,confidence,risk_level,original_hash,auto_generated) " +
    "SELECT 'news',?,?,?,?,?,?,?,'published',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,?,95,'GREEN',?,0 " +
    "WHERE NOT EXISTS(SELECT 1 FROM la_content WHERE slug=? OR source_url=? OR (lower(title) LIKE '%non puoi capire%' AND content_type IN ('news','event')))"
  ).bind(slug,editorDraft.headline,editorDraft.summary,editorDraft.body,area_id,category_id,source_url,event_start,sourceHash,slug,source_url).run();
  if(Number(ins.meta?.changes||0)!==1) return {status:"duplicate"};
  return {status:"published",content_id:Number(ins.meta?.last_row_id)};
}
