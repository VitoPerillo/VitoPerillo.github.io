/**
 * Ritratto Stellare — astronomical calculation core (isolated QA branch)
 * Astronomical positions: Astronomy Engine 2.1.19 (pinned).
 * Angles/houses: tropical Asc/MC from local sidereal time; Whole Sign houses.
 * IMPORTANT: local civil birth time must be converted with a real IANA timezone
 * before calling calculateChart(). Never infer or invent an unknown birth time.
 */
import * as Astronomy from "https://cdn.jsdelivr.net/npm/astronomy-engine@2.1.19/+esm";

const D2R=Math.PI/180, R2D=180/Math.PI;
const norm=x=>((x%360)+360)%360;
const bodies=["Sun","Moon","Mercury","Venus","Mars","Jupiter","Saturn","Uranus","Neptune","Pluto"];

export function eclipticLongitude(body,date){
  const b=Astronomy.Body[body];
  if(!b) throw new Error("Unsupported body: "+body);
  return norm(Astronomy.EclipticLongitude(b,date));
}
export function meanObliquityDeg(date){
  // Meeus polynomial, sufficient for angle calculation in the supported era.
  const jd=2440587.5+date.getTime()/86400000;
  const T=(jd-2451545.0)/36525;
  return 23.4392911111-0.0130041667*T-0.0000001639*T*T+0.0000005036*T*T*T;
}
export function angles(date,lat,lon){
  if(!Number.isFinite(lat)||lat<-90||lat>90) throw new Error("Invalid latitude");
  if(!Number.isFinite(lon)||lon<-180||lon>180) throw new Error("Invalid longitude");
  const eps=meanObliquityDeg(date)*D2R;
  const theta=norm(Astronomy.SiderealTime(date)*15+lon)*D2R;
  const phi=lat*D2R;
  const mc=norm(Math.atan2(Math.sin(theta),Math.cos(theta)*Math.cos(eps))*R2D);
  const asc=norm(Math.atan2(-Math.cos(theta),Math.sin(theta)*Math.cos(eps)+Math.tan(phi)*Math.sin(eps))*R2D+180);
  return {ascendant:asc,mc};
}
export function wholeSignCusps(ascendant){
  const start=Math.floor(norm(ascendant)/30)*30;
  return Array.from({length:12},(_,i)=>norm(start+i*30));
}

export function localCivilToUtc({year,month,day,hour=0,minute=0,second=0,timeZone}){
  if(!timeZone) throw new Error("IANA timezone required");
  const wanted=Date.UTC(year,month-1,day,hour,minute,second);
  const fmt=new Intl.DateTimeFormat("en-CA",{timeZone,year:"numeric",month:"2-digit",day:"2-digit",hour:"2-digit",minute:"2-digit",second:"2-digit",hourCycle:"h23"});
  let guess=wanted;
  for(let i=0;i<4;i++){
    const p=Object.fromEntries(fmt.formatToParts(new Date(guess)).filter(x=>x.type!=="literal").map(x=>[x.type,Number(x.value)]));
    const shown=Date.UTC(p.year,p.month-1,p.day,p.hour,p.minute,p.second);
    const delta=wanted-shown;
    if(delta===0) return new Date(guess);
    guess+=delta;
  }
  const p=Object.fromEntries(fmt.formatToParts(new Date(guess)).filter(x=>x.type!=="literal").map(x=>[x.type,Number(x.value)]));
  if(Date.UTC(p.year,p.month-1,p.day,p.hour,p.minute,p.second)!==wanted) throw new Error("Nonexistent or ambiguous local civil time");
  return new Date(guess);
}
export function aspectDelta(a,b){const d=Math.abs(norm(a-b));return Math.min(d,360-d);}
export function calculateChart({utcDate,lat,lon,timeKnown=true}){
  if(!(utcDate instanceof Date)||Number.isNaN(utcDate.getTime())) throw new Error("Invalid UTC date");
  const planets=Object.fromEntries(bodies.map(b=>[b,eclipticLongitude(b,utcDate)]));
  if(!timeKnown) return {utcDate:utcDate.toISOString(),planets,timeKnown:false,angles:null,houses:null};
  const a=angles(utcDate,lat,lon);
  return {utcDate:utcDate.toISOString(),planets,timeKnown:true,angles:a,houses:{system:"Whole Sign",cusps:wholeSignCusps(a.ascendant)}};
}
export function transitsAt(date){return Object.fromEntries(bodies.map(b=>[b,eclipticLongitude(b,date)]));}
export function periodSamples(start,end,stepDays=1){
  const out=[]; for(let t=start.getTime();t<=end.getTime();t+=stepDays*86400000){const d=new Date(t);out.push({date:d.toISOString(),transits:transitsAt(d)});} return out;
}
