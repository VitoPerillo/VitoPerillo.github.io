import test from 'node:test'; import assert from 'node:assert/strict';
import {stripImageMetadata,safeSourceUrl} from '../src/security/security.js';

test('P1 JPEG EXIF/APP metadata stripped where possible',()=>{
  const src=new Uint8Array([0xFF,0xD8, 0xFF,0xE1,0x00,0x08, 0x45,0x78,0x69,0x66,0,0, 0xFF,0xDA,0x00,0x02, 0x11,0x22]);
  const out=stripImageMetadata(src,'image/jpeg');
  assert.equal([...out].includes(0xE1),false);
  assert.equal(out[0],0xFF); assert.equal(out[1],0xD8);
});
test('P0 private/localhost source URLs blocked',()=>{assert.equal(safeSourceUrl('http://127.0.0.1/x'),false);assert.equal(safeSourceUrl('http://192.168.1.2/x'),false);assert.equal(safeSourceUrl('https://example.org/feed'),true);});
