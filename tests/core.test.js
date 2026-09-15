import test from 'node:test'; import assert from 'node:assert/strict';
import {riskGate,valueGate,factGate} from '../src/core/gates.js'; import {similarity} from '../src/core/dedupe.js';

test('P0 RED content never auto-publishes',()=>assert.equal(riskGate({title:'Denuncia contro Mario Rossi',text:'accusa grave'},90).level,'RED'));
test('P0 low local value rejected',()=>assert.equal(valueGate({title:'Breve',text:'testo',area_id:1}).pass,false));
test('P0 local utility accepted',()=>assert.equal(valueGate({title:'Chiusura temporanea di via Vitellia',text:'Lavori e chiusura in via Vitellia domani dalle 09:00 alle 13:00. La modifica interessa il traffico del quartiere e gli accessi locali.',area_id:1}).pass,true));
test('P0 AI changed time blocked',()=>assert.equal(factGate({title:'Chiusura',text:'Via Roma chiusa 09:00-13:00'},{headline:'Chiusura',summary:'',body:'Via Roma chiusa 09:00-18:00'}).pass,false));
test('P0 AI invented address blocked',()=>assert.equal(factGate({title:'Evento',text:'Evento al parco'},{headline:'Evento',summary:'',body:'Evento al parco',address:'Via Inventata 22'}).pass,false));
test('P1 title similarity catches borderline duplicate',()=>assert.ok(similarity('Chiusura via Vitellia per lavori stradali','Lavori stradali: chiude via Vitellia')>=0.5));
