import assert from "node:assert/strict";import fs from "node:fs/promises";
for(const n of ["rs-astro-engine","rs-portrait-engine","rs-period-engine"]){let s=await fs.readFile("ritratto-public/js/"+n+".js","utf8");s=s.replace("https://cdn.jsdelivr.net/npm/astronomy-engine@2.1.19/+esm","astronomy-engine").replaceAll('from "./rs-astro-engine.js"','from "./.rs-astro-engine.tmp.mjs"').replaceAll('from "./rs-portrait-engine.js"','from "./.rs-portrait-engine.tmp.mjs"');await fs.writeFile("ritratto-public/js/."+n+".tmp.mjs",s);}
const A=await import("../js/.rs-astro-engine.tmp.mjs");const P=await import("../js/.rs-period-engine.tmp.mjs");
const ny=A.calculateChart({utcDate:new Date("1990-05-15T14:30:00Z"),lat:40.7128,lon:-74.006,timeKnown:true});
const rome=A.calculateChart({utcDate:new Date("1982-11-03T06:20:00Z"),lat:41.9028,lon:12.4964,timeKnown:true});
const local={year:2026,month:10,day:3,timeZone:"Europe/Rome"};
for(const period of ["today","week","month","year"]){const x=P.buildPersonalPeriodReading({natalChart:ny,localDate:local,period,plan:"andromeda",profile:{name:"NY"}});const y=P.buildPersonalPeriodReading({natalChart:rome,localDate:local,period,plan:"andromeda",profile:{name:"Roma"}});assert.notEqual(JSON.stringify(x.events),JSON.stringify(y.events),period+" must differ by natal chart");assert.equal(x.source.method,"natal chart + real transits + natal↔transit major aspects");assert.ok(x.source.window.start&&x.source.window.endExclusive);}
const d1=P.buildPersonalPeriodReading({natalChart:ny,localDate:{...local,day:3},period:"today",plan:"fenice",answers:{focus:"lavoro"}});
const d2=P.buildPersonalPeriodReading({natalChart:ny,localDate:{...local,day:10},period:"today",plan:"fenice",answers:{focus:"lavoro"}});
assert.notEqual(JSON.stringify(d1.events),JSON.stringify(d2.events),"same person different day must use different transits");
const plans=["pegaso","fenice","andromeda"].map(plan=>P.buildPersonalPeriodReading({natalChart:ny,localDate:local,period:"month",plan}));
assert.deepEqual(plans.map(x=>x.limits.areas),[4,8,12]);assert.deepEqual(plans.map(x=>x.limits.questionsPerMonth),[20,60,150]);assert.deepEqual(plans.map(x=>x.areas.length),[4,8,12]);
const unknown=A.calculateChart({utcDate:new Date("1990-05-15T12:00:00Z"),lat:40.7128,lon:-74.006,timeKnown:false});const u=P.buildPersonalPeriodReading({natalChart:unknown,localDate:local,period:"year",plan:"andromeda"});assert.match(u.integrity,/Ora sconosciuta/);assert.equal(u.source.timeKnown,false);
console.log("PERSONAL HOROSCOPE GATE PASS",JSON.stringify({periods:["today","week","month","year"],differentPeople:true,differentDates:true,realNatalTransitAspects:true,unknownTimeSafe:true,plans:{areas:[4,8,12],questions:[20,60,150]}}));
