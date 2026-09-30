import assert from "node:assert/strict";
import fs from "node:fs/promises";
// A-I QA — isolated branch. Reference I: Swiss Ephemeris published example.
const src=await fs.readFile("ritratto-public/js/rs-astro-engine.js","utf8");
await fs.writeFile("/tmp/rs-astro-engine.mjs",src.replace("https://cdn.jsdelivr.net/npm/astronomy-engine@2.1.19/+esm","astronomy-engine"));
const E=await import("file:///tmp/rs-astro-engine.mjs");
const near=(a,b,t,msg)=>assert.ok(Math.abs(a-b)<=t,`${msg}: ${a} vs ${b}`);
const base={utcDate:new Date("1990-05-15T14:30:00Z"),lat:40.7128,lon:-74.006,timeKnown:true};
const a=E.calculateChart(base), a2=E.calculateChart(base); assert.deepEqual(a,a2,"A deterministic");
const b=E.calculateChart({...base,utcDate:new Date("1990-05-16T14:30:00Z")}); assert.notEqual(a.planets.Moon,b.planets.Moon,"B date");
const c=E.calculateChart({...base,utcDate:new Date("1990-05-15T16:30:00Z")}); assert.ok(E.aspectDelta(a.angles.ascendant,c.angles.ascendant)>10,"C time Asc"); assert.ok(E.aspectDelta(a.planets.Pluto,c.planets.Pluto)<0.1,"C Pluto");
const d=E.calculateChart({...base,lat:41.9028,lon:12.4964}); assert.ok(E.aspectDelta(a.angles.ascendant,d.angles.ascendant)>1,"D place Asc"); near(a.planets.Sun,d.planets.Sun,1e-10,"D Sun");
const u=E.calculateChart({...base,timeKnown:false}); assert.equal(u.angles,null); assert.equal(u.houses,null);
const t1=E.transitsAt(new Date("2026-09-29T12:00:00Z")),t2=E.transitsAt(new Date("2026-10-06T12:00:00Z")); assert.ok(E.aspectDelta(t1.Moon,t2.Moon)>1,"F transits");
for(const days of [1,7,30,365]){const p=E.periodSamples(new Date("2026-01-01T00:00:00Z"),new Date(Date.UTC(2026,0,1+days)));assert.ok(p.length>=2,"G periods");}
const plans=["pegaso","fenice","andromeda"].map(()=>E.calculateChart(base)); assert.deepEqual(plans[0],plans[1]);assert.deepEqual(plans[1],plans[2]);
const refs=[
 {name:"New York 1990",local:{year:1990,month:5,day:15,hour:10,minute:30,timeZone:"America/New_York"},lat:40.7128,lon:-74.006,utc:"1990-05-15T14:30:00.000Z",p:[54.496555506,298.450071589,38.000338678,12.937142407,348.411218279,99.561406572,295.247674922,279.181350068,284.351369934,226.164555074],asc:122.203664553,mc:17.950040994},
 {name:"Rome DST 2026",local:{year:2026,month:7,day:1,hour:12,minute:0,timeZone:"Europe/Rome"},lat:41.9028,lon:12.4964,utc:"2026-07-01T10:00:00.000Z",p:[99.600031894,295.214557075,116.148821186,140.665325027,61.854755107,120.250942806,14.204525721,63.728332168,4.407975048,304.867314532],asc:173.702269772,mc:82.626979360},
 {name:"Sydney DST 2000",local:{year:2000,month:12,day:1,hour:23,minute:45,timeZone:"Australia/Sydney"},lat:-33.8688,lon:151.2093,utc:"2000-12-01T12:45:00.000Z",p:[249.636623768,310.946747505,236.432551704,291.966528285,196.778185376,65.681944301,56.524304770,317.437782651,304.395410995,252.617410703],asc:127.905901895,mc:55.475344733}
];
const names=["Sun","Moon","Mercury","Venus","Mars","Jupiter","Saturn","Uranus","Neptune","Pluto"];
for(const r of refs){
 const utc=E.localCivilToUtc(r.local); assert.equal(utc.toISOString(),r.utc,r.name+" timezone");
 const ch=E.calculateChart({utcDate:utc,lat:r.lat,lon:r.lon,timeKnown:true});
 names.forEach((n,i)=>near(E.aspectDelta(ch.planets[n],r.p[i]),0,0.05,r.name+" "+n+" Swiss"));
 near(E.aspectDelta(ch.angles.ascendant,r.asc),0,0.02,r.name+" Asc Swiss");
 near(E.aspectDelta(ch.angles.mc,r.mc),0,0.02,r.name+" MC Swiss");
}
near(E.localCivilToUtc({year:1970,month:1,day:15,hour:8,minute:20,timeZone:"Europe/Rome"}).getTime(),Date.parse("1970-01-15T07:20:00Z"),1,"historical timezone");
console.log("A-I CORE QA PASS",JSON.stringify({sun:a.planets.Sun,moon:a.planets.Moon,asc:a.angles.ascendant,mc:a.angles.mc}));
