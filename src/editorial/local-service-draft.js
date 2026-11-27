// Conservative service-specific proposals and publisher contract. No direct writes or inference.
export function ahoBuildServiceDraft(record,detail){
  const hold=reason=>({status:"held",reason,draft:null});
  if(!record||!detail||detail.source_url!==record.source_url||typeof detail.text!=="string"||detail.text.length<300||detail.text.length>50000)return hold("full_source_required");
  const t=detail.text.normalize("NFC").replace(/\s+/g," ").trim();
  const url=record.source_url;
  if(Number(record.area_id||0)<=0||!/\bRoma\b/i.test(t))return hold("locality_unconfirmed");
  const dates=[...t.matchAll(/\b(?:sabato|domenica|lunedì|martedì|mercoledì|giovedì|venerdì)\s+(\d{1,2})\s+(gennaio|febbraio|marzo|aprile|maggio|giugno|luglio|agosto|settembre|ottobre|novembre|dicembre)\s+(\d{4})\b/gi)];
  const months="gennaio febbraio marzo aprile maggio giugno luglio agosto settembre ottobre novembre dicembre".split(" ");
  const dateParts=m=>{const y=Number(m[3]),mo=months.indexOf(m[2].toLowerCase())+1,d=Number(m[1]);const dt=new Date(Date.UTC(y,mo-1,d));return dt.getUTCFullYear()===y&&dt.getUTCMonth()===mo-1&&dt.getUTCDate()===d?`${y}-${String(mo).padStart(2,"0")}-${String(d).padStart(2,"0")}`:null;};
  const cleanField=s=>typeof s==="string"&&s.length>2&&s.length<=150&&!/[<>@]|https?:|www\./i.test(s);
  if(/^https:\/\/(?:www\.)?comune\.roma\.it\/web\/it\/informazione-di-servizio\.page\?contentId=IDS\d+$/i.test(url)&&/\bCIE\b/i.test(t)&&/sportelli anagrafici/i.test(t)){
    const municipality=t.match(/Municipio(?: Roma)? ([IVX]{1,5}|\d{1,2})\b/i);
    const opening=dates[0],openingTime=t.match(/aprono in via straordinaria dalle ore (\d{1,2}:\d{2}) alle ore (\d{1,2}:\d{2})/i);
    const addresses=t.match(/sportelli anagrafici di ((?:Piazza|Via|Viale|Largo) [^,.;]{2,65},\s*\d{1,4}[A-Za-z]?) e ((?:Piazza|Via|Viale|Largo) [^,.;]{2,65},\s*\d{1,4}[A-Za-z]?) aprono/i);
    const booking=t.match(/Prenotazione obbligatoria dalle ore (\d{1,2}:\d{2}) di ((?:lunedì|martedì|mercoledì|giovedì|venerdì|sabato|domenica) \d{1,2} (?:gennaio|febbraio|marzo|aprile|maggio|giugno|luglio|agosto|settembre|ottobre|novembre|dicembre)) fino a esaurimento disponibilità tramite Agenda CIE/i);
    if(!municipality||!opening||dates.length!==1||!dateParts(opening)||!openingTime||!addresses||!booking||!addresses.slice(1).every(cleanField))return hold("cie_ambiguous_evidence");
    if(!["ticket di prenotazione","fototessera","carta di pagamento elettronico","vecchio documento"].every(x=>t.toLowerCase().includes(x))||!/caricabile online durante la prenotazione/i.test(t)||!/consultare la scheda del servizio/i.test(t))return hold("cie_documentation_incomplete");
    const mins=s=>{const [h,m]=s.split(":").map(Number);return h<=23&&m<=59?h*60+m:-1;};
    if(mins(openingTime[1])<0||mins(openingTime[2])<=mins(openingTime[1])||mins(booking[1])<0)return hold("cie_invalid_time");
    const day=opening[0],start=openingTime[1],end=openingTime[2],mun=municipality[1],a=addresses[1],b=addresses[2];
    const headline=`Municipio ${mun}: apertura straordinaria degli sportelli per la CIE`;
    const summary=`Il ${day.toLowerCase()} il servizio anagrafico è previsto in ${a} e ${b}, dalle ${start} alle ${end}. Occorre prenotare.`;
    const body=[
      `Chi deve richiedere la carta d’identità elettronica a Roma può consultare l’avviso relativo al Municipio ${mun}. Per ${day.toLowerCase()} è stata annunciata una giornata straordinaria dedicata al rilascio del documento, con attività degli uffici dalle ${start} alle ${end}.`,
      `Sono interessate entrambe le sedi indicate nell’avviso: ${a} e ${b}. La fascia oraria comunicata si riferisce ai due sportelli. Per scegliere la sede occorre tenere conto della disponibilità degli appuntamenti, non soltanto dell’orario di apertura.`,
      `La prenotazione è obbligatoria. Le richieste di appuntamento possono essere presentate attraverso Agenda CIE a partire dalle ${booking[1]} di ${booking[2].toLowerCase()}, fino a quando saranno disponibili posti. L’avviso non garantisce quindi l’accesso agli uffici senza appuntamento.`,
      `Il promemoria per il giorno della richiesta comprende il ticket di prenotazione, il vecchio documento, una carta per pagare elettronicamente e la fototessera. Quest’ultima può essere trasmessa anche online nella fase in cui si prenota il turno. Per gli eventuali requisiti specifici della richiesta, il Comune rinvia alla scheda del servizio.`
    ].join("\n\n");
    return {status:"draft",reason:"cie_service_notice",kind:"municipal_service",identity:url,sharedCalendar:false,draft:{headline,summary,body,valid_until:dateParts(opening)+"T"+end.padStart(5,"0")+":00",valid_from:record.original_date||null,confidence:95,social_text:headline}};
  }
  if(url==="https://www.amaroma.it/it/servizi/raccolte-del-weekend"){
    const m=t.match(/Municipio (\d{1,2})\s*[—–-]/i),d=dates[0];
    const x=t.match(/\b(\d{1,2}:\d{2})\s*[-–]\s*(\d{1,2}:\d{2})\b/);
    const place=t.match(/\d{4}\.\s*([^,.;]{3,80}),\s*([^.;]{3,150}),\s*orario/i);
    const materials=t.match(/Raccolta di ([^.]{20,220})\./i);
    if(!m||Number(m[1])<1||Number(m[1])>15||dates.length!==1||!d||!dateParts(d)||!x||!place||!materials||!place.slice(1).every(cleanField))return hold("ama_single_card_required");
    const [sh,sm]=x[1].split(":").map(Number),[eh,em]=x[2].split(":").map(Number);
    if(sh>23||eh>23||sm>59||em>59||eh*60+em<=sh*60+sm)return hold("ama_invalid_time");
    const list=materials[1].split(/,\s*/).filter(Boolean);
    if(list.length<3||list.length>12||!list.every(cleanField))return hold("ama_materials_ambiguous");
    const identity=`ama-weekend:${m[1]}:${dateParts(d)}:${place[1].toLowerCase().normalize("NFKD").replace(/\p{M}/gu,"").replace(/[^a-z0-9]+/g,"-")}`;
    const headline=`Municipio ${m[1]}: raccolta AMA presso ${place[1]} il ${d[0].toLowerCase()}`;
    const summary=`Nel Municipio ${m[1]} di Roma è prevista una raccolta di materiali dalle ${x[1]} alle ${x[2]}, presso ${place[1]}.`;
    const body=`Il calendario delle raccolte del weekend di AMA indica un appuntamento nel Municipio ${m[1]} di Roma per ${d[0].toLowerCase()}. Il punto di consegna è ${place[1]}; l’indicazione per individuare la postazione è ${place[2]}. La raccolta è programmata dalle ${x[1]} alle ${x[2]}.\n\nFra le tipologie indicate figurano ${list.join("; ")}. L’elenco riguarda questa postazione: per materiali diversi occorre verificare le indicazioni del gestore. Il riferimento per eventuali variazioni di sede o di orario è il calendario ufficiale di AMA.`;
    return {status:"draft",reason:"ama_calendar_shared_url",kind:"environment",identity,sharedCalendar:true,draft:{headline,summary,body,valid_until:dateParts(d)+"T"+x[2].padStart(5,"0")+":00",valid_from:record.original_date||null,confidence:95,social_text:headline}};
  }
  return hold("unsupported_service_format");
}

export function ahoPlanReviewedHandoff({proposal,gates,record,row,detail,context,sourceHash,verifiedAt}){
  const hold=reason=>({status:"held",reason,payload:null});
  if(!proposal?.draft)return hold("no_draft");
  if(proposal.sharedCalendar)return hold("shared_url_requires_event_identity");
  if(!gates||gates.risk?.level!=="GREEN"||!["value","editorial","facts","expiry"].every(k=>gates[k]?.pass===true))return hold("native_gate_failed");
  if(context?.duplicate!==false)return hold("archive_dedup_required");
  if(context?.go!==true||context?.rightsVerified!==true||context?.freshPrimary!==true)return hold("legal_or_source_gate_missing");
  if(!record?.source_url||record.source_url!==row?.source_url||detail?.source_url!==row.source_url||detail?.text!==record.text||!Number.isInteger(Number(row.id))||Number(row.id)<=0||!/^([a-f0-9]{64})$/.test(String(sourceHash))||!Number.isFinite(Date.parse(verifiedAt)))return hold("source_contract_invalid");
  if(!["new","held"].includes(row.status)||row.content_id)return hold("ingest_not_available");
  return {status:"ready_for_queue",reason:"all_gates_pass",payload:{schema:"aho-reviewed-editorial/1",verified_at:verifiedAt,expected_ingest_hash:sourceHash,source:{source_url:row.source_url,title:record.title,text:detail.text,date:record.original_date||null},draft:proposal.draft,origin:"aho-deterministic-v1"}};
}
