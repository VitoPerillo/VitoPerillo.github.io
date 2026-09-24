const { test, expect } = require('@playwright/test');

async function pick(page,label){
  const b=page.getByRole('button',{name:label,exact:true});
  await expect(b).toBeVisible(); await b.click();
  await page.getByRole('button',{name:/AVANTI|VEDI LA PROPOSTA/}).click();
}

test.describe('YOGANOSTRESS ADESSO — Dummy Gate',()=>{
  test('dummy capisce subito cosa fare',async({page})=>{
    await page.goto('/qa-harness.html');
    await expect(page.getByRole('heading',{name:/La pratica giusta/})).toBeVisible();
    await expect(page.getByText(/una sola pratica/i)).toBeVisible();
    await page.getByRole('button',{name:'FACCIO IL RESET TEST'}).click();
    await expect(page.getByText('Domanda 1 di 7')).toBeVisible();
    await expect(page.locator('.ysad-wizard-title')).toHaveCount(1);
    await pick(page,'Abbastanza'); await pick(page,'Moderata'); await pick(page,'Media'); await pick(page,'Così così'); await pick(page,'Nessun dolore'); await pick(page,'Sì, stabile / già conosciuta');
    await page.getByRole('button',{name:'7 minuti'}).click();
    await page.getByRole('button',{name:'VEDI LA PROPOSTA'}).click();
    await expect(page.getByText('LA PROPOSTA PER ADESSO')).toBeVisible();
    await expect(page.getByText(/Safety Gate:/)).toBeVisible();
  });

  test('dolore importante blocca la pratica fisica',async({page})=>{
    await page.goto('/qa-harness.html'); await page.getByRole('button',{name:'FACCIO IL RESET TEST'}).click();
    await pick(page,'Abbastanza'); await pick(page,'Moderata'); await pick(page,'Media'); await pick(page,'Così così'); await pick(page,'Dolore importante'); await pick(page,'No, è nuovo o peggiorato');
    await page.getByRole('button',{name:'7 minuti'}).click(); await page.getByRole('button',{name:'VEDI LA PROPOSTA'}).click();
    await expect(page.getByRole('heading',{name:'Supporto prudente non fisico'})).toBeVisible();
    await expect(page.getByText(/niente pratica fisica automatica/i)).toBeVisible();
  });

  test('menu primario ha 5 voci e Piani è nel Profilo',async({page})=>{
    await page.goto('/qa-harness.html?logged=1');
    const nav=page.locator('#ysad-nav'); await expect(nav.getByRole('button')).toHaveCount(5);
    await expect(nav.getByRole('button',{name:'Piani'})).toHaveCount(0);
    await nav.getByRole('button',{name:'Profilo'}).click();
    await expect(page.getByRole('button',{name:'VEDI I PIANI'})).toBeVisible();
  });

  test('check-in loggato è una domanda alla volta e niente 20/30',async({page})=>{
    await page.goto('/qa-harness.html?logged=1');
    await expect(page.getByText('Domanda 1 di 9')).toBeVisible();
    await expect(page.getByRole('button',{name:'20 minuti'})).toHaveCount(0);
    await expect(page.getByRole('button',{name:'30 minuti'})).toHaveCount(0);
  });

  test('nessun overflow orizzontale',async({page})=>{
    await page.goto('/qa-harness.html');
    expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1)).toBeTruthy();
  });
});