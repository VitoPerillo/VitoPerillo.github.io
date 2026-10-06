const PLACES=Object.freeze({
 "roma|italia":{label:"Roma, Italia",lat:41.9028,lon:12.4964,timeZone:"Europe/Rome"},
 "rome|italy":{label:"Roma, Italia",lat:41.9028,lon:12.4964,timeZone:"Europe/Rome"},
 "new york|usa":{label:"New York, USA",lat:40.7128,lon:-74.0060,timeZone:"America/New_York"},
 "sydney|australia":{label:"Sydney, Australia",lat:-33.8688,lon:151.2093,timeZone:"Australia/Sydney"}
});
const norm=s=>String(s||"").trim().toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g,"").replace(/\s+/g," ");
export function resolveBirthPlace(input){
 const n=norm(input),exact=PLACES[n];if(exact)return {...exact,source:"verified-catalog"};
 for(const [k,v] of Object.entries(PLACES)){const city=k.split("|")[0];if(n===city||n.startsWith(city+",")||n.startsWith(city+" "))return {...v,source:"verified-catalog"};}
 throw new Error("birth_place_not_resolved");
}
export function birthInputToChartArgs({birthDate,birthTime,birthPlace},astro){
 const loc=resolveBirthPlace(birthPlace);const [year,month,day]=birthDate.split("-").map(Number);if(!year||!month||!day)throw new Error("invalid_birth_date");
 if(!birthTime)return {location:loc,timeKnown:false,moonUncertainty:astro.unknownTimeMoonRange({year,month,day,timeZone:loc.timeZone}),chartArgs:{utcDate:astro.localCivilToUtc({year,month,day,hour:12,timeZone:loc.timeZone}),lat:loc.lat,lon:loc.lon,timeKnown:false}};
 const [hour,minute]=birthTime.split(":").map(Number);const utcDate=astro.localCivilToUtc({year,month,day,hour,minute,timeZone:loc.timeZone});
 return {location:loc,timeKnown:true,moonUncertainty:null,chartArgs:{utcDate,lat:loc.lat,lon:loc.lon,timeKnown:true}};
}
