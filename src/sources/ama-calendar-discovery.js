// Parse a rendered AMA calendar into distinct, HELD factual leads. Never treats event date as publication date.
export function ahoAmaCalendarCandidates({sourceUrl,text,fromDay,throughDay}){
 const source="https://www.amaroma.it/it/servizi/raccolte-del-weekend";
 const fail=reason=>({status:"held",reason,items:[],conflicts:[]});
 const iso=v=>{if(typeof v!=="string"||!/^\d{4}-\d{2}-\d{2}$/.test(v))return null;const t=Date.parse(v+"T00:00:00Z");return Number.isFinite(t)&&new Date(t).toISOString().slice(0,10)===v?t:null;};
 const from=iso(fromDay),through=iso(throughDay);
 if(sourceUrl!==source||typeof text!=="string"||text.length>1000000||from===null||through===null||through<from||through-from>31*86400000)return fail("invalid_input_or_window");
 const months="gennaio febbraio marzo aprile maggio giugno luglio agosto settembre ottobre novembre dicembre".split(" ");
 const weekdays="domenica lunedì martedì mercoledì giovedì venerdì sabato".split(" ");
 const lines=text.normalize("NFC").split(/\r?\n/).map(x=>x.trim().replace(/^\s*[-*]\s+/,"").replace(/\s+/g," ")).filter(Boolean);
 const header=/^Municipio\s+(\d{1,2})\s*[—–-]\s*(domenica|lunedì|martedì|mercoledì|giovedì|venerdì|sabato)\s+(\d{1,2})\s+(gennaio|febbraio|marzo|aprile|maggio|giugno|luglio|agosto|settembre|ottobre|novembre|dicembre)\s+(\d{4})$/i;
 const time=/^(\d{2}):(\d{2})\s*[-–]\s*(\d{2}):(\d{2})$/;
 const material=new Map(["Ingombranti","Apparati elettronici","Metalli","Legno","Monitor e TV","Tessili, abiti e accessori","Piccoli Elettrodomestici","Sfalci e potature"].map(x=>[x.toLocaleLowerCase("it-IT"),x]));
 const starts=[];for(let i=0;i<lines.length;i++)if(header.test(lines[i]))starts.push(i);
 const accepted=new Map(),conflicts=new Set();let held=0,stale=0;
 const canonical=v=>v.toLocaleLowerCase("it-IT").normalize("NFKD").replace(/\p{M}/gu,"").replace(/[^\p{L}\p{N}]+/gu,"-").replace(/^-|-$/g,"");
 for(let k=0;k<starts.length;k++){
  const i=starts[k],m=lines[i].match(header),mun=Number(m[1]),day=`${m[5]}-${String(months.indexOf(m[4].toLowerCase())+1).padStart(2,"0")}-${m[3].padStart(2,"0")}`,t=iso(day);
  if(t===null||mun<1||mun>15||weekdays[new Date(t).getUTCDay()]!==m[2].toLowerCase()){held++;continue}
  if(t<from||t>through){stale++;continue}
  const block=lines.slice(i+1,Math.min(starts[k+1]??lines.length,i+25));
  const ti=block.findIndex(x=>time.test(x)),li=block.findIndex(x=>/^Puoi portare:$/i.test(x));
  if(ti<1||ti>2||li!==ti+1){held++;continue}
  const place=block[0],hint=ti===2?block[1]:null;
  if(!/^(?:via|viale|piazza|piazzale|largo|lungotevere|parco|parcheggio|vicolo|borgo|circonvallazione|strada|corso|area)\b/i.test(place)||[place,hint].filter(Boolean).some(x=>x.length>150||/[<>@]|https?:|www\./i.test(x))){held++;continue}
  const h=block[ti].match(time),[sh,sm,eh,em]=h.slice(1).map(Number);
  if(sh>23||eh>23||sm>59||em>59||eh*60+em<=sh*60+sm){held++;continue}
  const materials=[];let bad=false;
  for(const line of block.slice(li+1)){
   if(/^\d{1,2}$/.test(line)||/^(?:GEN|FEB|MAR|APR|MAG|GIU|LUG|AGO|SET|OTT|NOV|DIC)$/i.test(line)||/^(?:lun|mar|mer|gio|ven|sab|dom)$/i.test(line))break;
   const val=material.get(line.toLocaleLowerCase("it-IT"));
   if(!val){bad=true;break}if(materials.includes(val)){bad=true;break}materials.push(val);
  }
  if(bad||materials.length<3){held++;continue}
  const key=`ama-weekend:${mun}:${day}:${canonical(place)}${hint?":"+canonical(hint):""}`;
  const facts={municipality:mun,date:day,place,hint,start:h[1]+":"+h[2],end:h[3]+":"+h[4],materials};
  const item={external_id:key,source_url:source,title:`Raccolta AMA, Municipio ${mun}, ${place}, ${day}`,date:null,event_date:day,status:"held",reason:"shared_calendar_needs_publisher_identity_and_rights",facts};
  if(conflicts.has(key))continue;
  if(accepted.has(key)){if(JSON.stringify(accepted.get(key).facts)!==JSON.stringify(facts)){accepted.delete(key);conflicts.add(key);}continue;}
  accepted.set(key,item);
 }
 return {status:"discovery_only",items:[...accepted.values()].sort((a,b)=>a.event_date.localeCompare(b.event_date)||a.external_id.localeCompare(b.external_id)),conflicts:[...conflicts],held,excludedByWindow:stale,headers:starts.length};
}
