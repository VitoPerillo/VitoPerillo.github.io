import assert from "node:assert/strict";import fs from "node:fs/promises";
const prep=async(n,repls=[])=>{let s=await fs.readFile("ritratto-public/js/"+n+".js","utf8");s=s.replace("https://cdn.jsdelivr.net/npm/astronomy-engine@2.1.19/+esm","astronomy-engine");for(const [a,b]of repls)s=s.replaceAll(a,b);const p="ritratto-public/js/."+n+".gate.mjs";await fs.writeFile(p,s);return import("../js/."+n+".gate.mjs?x="+Date.now());};
const A=await prep("rs-astro-engine");const L=await prep("rs-birth-location");await prep("rs-portrait-engine");const P=await prep("rs-period-engine",[['from "./rs-astro-engine.js"','from "./.rs-astro-engine.gate.mjs"'],['from "./rs-portrait-engine.js"','from "./.rs-portrait-engine.gate.mjs"']]);
const profiles=[
{name:"Roma",birthDate:"1973-01-15",birthTime:"08:20",birthPlace:"Roma, Italia"},
{name:"NY",birthDate:"1990-05-15",birthTime:"10:30",birthPlace:"New York, USA"},
{name:"Sydney",birthDate:"2000-12-01",birthTime:"23:45",birthPlace:"Sydney, Australia"}];
const charts=profiles.map(x=>{const z=L.birthInputToChartArgs(x,A);return A.calculateChart(z.chartArgs)});
assert.notDeepEqual(charts[0].planets,charts[1].planets);assert.notDeepEqual(charts[1].planets,charts[2].planets);
const local={year:2026,month:10,day:6,timeZone:"Europe/Rome"};
for(const period of ["today","week","month","year"]){const reads=charts.map((n,i)=>P.buildPersonalPeriodReading({natalChart:n,localDate:local,period,plan:"andromeda",profile:profiles[i]}));assert.equal(new Set(reads.map(x=>JSON.stringify(x.events))).size,3,period+" individualized");for(const x of reads){assert.equal(x.source.method,"natal chart + real transits + natal↔transit major aspects");assert.ok(x.source.window.start&&x.source.window.endExclusive);}}
const x=L.birthInputToChartArgs({birthDate:"1990-05-15",birthTime:"",birthPlace:"New York"},A);assert.equal(x.timeKnown,false);assert.equal(x.moonUncertainty.positionCertain,false);const xu=A.calculateChart(x.chartArgs);assert.equal(xu.angles,null);assert.equal(xu.houses,null);
assert.throws(()=>L.resolveBirthPlace("Luogo non verificato"),/not_resolved/);
const p=["pegaso","fenice","andromeda"].map(plan=>P.buildPersonalPeriodReading({natalChart:charts[0],localDate:local,period:"month",plan}));assert.deepEqual(p.map(x=>x.limits.areas),[4,8,12]);assert.deepEqual(p.map(x=>x.limits.questionsPerMonth),[20,60,150]);
console.log("GATE 1 FULL PASS",JSON.stringify({birthLocationTimezone:true,natalPersonalization:true,periods:["today","week","month","year"],differentPeopleSamePeriod:true,unknownTimeSafe:true,unverifiedPlaceHardStop:true,areas:[4,8,12],questions:[20,60,150]}));