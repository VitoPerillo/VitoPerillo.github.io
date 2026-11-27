// Structured facts only. No external inference, no publishing authority.
export function ahoBuildEventDraft(record,detail,now=Date.now()){
  const hold=reason=>({status:"held",reason,draft:null});
  if(!record||!detail||typeof detail.text!=="string"||detail.text.length<300||detail.text.length>50000||record.source_url!==detail.source_url)return hold("full_source_required");
  if(!/^https:\/\/(?:www\.comune\.roma\.it|comune\.roma\.it|culture\.roma\.it|www\.sovraintendenzaroma\.it|sovraintendenzaroma\.it)\/[^\s\\<>]*$/i.test(record.source_url))return hold("source_not_supported");
  const source=detail.text.normalize("NFC"), map=new Map();
  const labels=new Map([["evento","event"],["titolo evento","event"],["data","date"],["orario","time"],["luogo","venue"],["indirizzo","address"],["organizzatore","organizer"],["ingresso","price"],["prenotazione","booking"],["accessibilità","access"],["lingua","language"],["posti","capacity"],["durata","duration"],["programma","program"],["destinatari","audience"]]);
  for(const line of source.split(/\r?\n/)){
    const m=line.trim().match(/^([^:\n]{2,30})\s*:\s*(.+)$/u);if(!m)continue;
    const k=labels.get(m[1].trim().toLocaleLowerCase("it-IT"));if(!k)continue;
    const v=m[2].trim().replace(/\s+/g," ");
    if(map.has(k)||v.length>110||v.split(" ").length>10||/[<>@]|https?:|www\.|[\u0000-\u001f]/iu.test(v))return hold("ambiguous_or_unbounded_field");
    map.set(k,v);
  }
  for(const k of ["event","date","time","venue","address","organizer","price","booking"])if(!map.get(k))return hold("insufficient_structured_facts");
  const get=k=>map.get(k);
  if(!/\broma\b/i.test(get("address"))||Number(record.area_id||0)<=0)return hold("locality_unconfirmed");
  const date=get("date").match(/^(\d{4})-(\d{2})-(\d{2})$/);
  const time=get("time").match(/^(?:dalle )?(\d{2}):(\d{2})\s*(?:-|–|alle)\s*(\d{2}):(\d{2})$/);
  if(!date||!time)return hold("explicit_date_and_end_time_required");
  const [,y,mo,d]=date, [,sh,sm,eh,em]=time;
  const calendar=new Date(Date.UTC(+y,+mo-1,+d));
  if(calendar.getUTCFullYear()!==+y||calendar.getUTCMonth()!==+mo-1||calendar.getUTCDate()!==+d||+sh>23||+eh>23||+sm>59||+em>59||(+eh*60 + +em)<=(+sh*60 + +sm))return hold("invalid_or_overnight_schedule");
  const endWall=`${get("date")}T${eh}:${em}:00`;
  if(Date.parse(endWall+"Z")<=now)return hold("expired_schedule");
  const headline=`${get("event")}: appuntamento a Roma il ${get("date")}`;
  const summary=`${get("event")} si svolge presso ${get("venue")}, il ${get("date")}, dalle ${sh}:${sm} alle ${eh}:${em}.`;
  const paragraphs=[
    `A Roma è in programma ${get("event")}. L'iniziativa si svolge il ${get("date")} presso ${get("venue")}, con inizio alle ${sh}:${sm} e conclusione alle ${eh}:${em}.`,
    `La sede indicata è ${get("address")}. L'organizzazione è affidata a ${get("organizer")}. Per quanto riguarda l'accesso, la scheda ufficiale riporta: ${get("price")}. La modalità di prenotazione indicata è ${get("booking")}.`
  ];
  const details=[];
  if(get("program"))details.push(`Il programma prevede ${get("program")}.`);
  if(get("audience"))details.push(`L'iniziativa si rivolge a ${get("audience")}.`);
  if(get("capacity"))details.push(`La disponibilità indicata è di ${get("capacity")}.`);
  if(get("duration"))details.push(`La durata riportata è ${get("duration")}.`);
  if(get("language"))details.push(`La lingua indicata è ${get("language")}.`);
  if(get("access"))details.push(`Per l'accessibilità la scheda specifica: ${get("access")}.`);
  if(details.length)paragraphs.push(details.join(" "));
  const body=paragraphs.join("\n\n");
  const tokens=s=>s.toLocaleLowerCase("it-IT").match(/[\p{L}\p{N}]+/gu)||[];
  const sw=tokens(source),bw=tokens(`${headline} ${summary} ${body}`);
  const copied=new Set();for(let i=0;i+11<sw.length;i++)copied.add(sw.slice(i,i+12).join(" "));
  for(let i=0;i+11<bw.length;i++)if(copied.has(bw.slice(i,i+12).join(" ")))return hold("verbatim_overlap");
  const draft={headline,summary,body,valid_until:endWall,valid_from:record.original_date||null,confidence:95,social_text:headline};
  return {status:"draft",reason:"structured_event_only",draft,fields:map.size,words:body.split(/\s+/).length};
}
