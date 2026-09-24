const { test, expect } = require('@playwright/test');

const URL = process.env.DUMMY_URL;
if (!URL) throw new Error('DUMMY_URL missing');

for (const profile of [
  { name: 'mobile', viewport: { width: 390, height: 844 } },
  { name: 'desktop', viewport: { width: 1440, height: 1000 } },
]) {
  test(`Dumy Gate ${profile.name}`, async ({ browser }) => {
    const page = await browser.newPage({ viewport: profile.viewport });
    const consoleErrors = [];
    page.on('console', msg => { if (msg.type() === 'error') consoleErrors.push(msg.text()); });
    await page.goto(URL { waitUntil: 'networkidle' });

    await expect(page.getByText('La pratica giusta per come stai oggi.').first()).toBeVisible();
    await expect(page.getByRole('button', { name: 'DIMMI COME STAI' })).toBeVisible();

    const overflow = await page.evaluate(() =>
      document.documentElement.scrollWidth > document.documentElement.clientWidth + 2
    );
    expect(overflow, 'No horizontal overflow on landing').toBeFalsy();

    await page.getByRole('button', { name: 'DIMMI COME STAI' }).click();
    await expect(page.locator('#qcount')).toHaveText('Domanda 1 di 5');

    for (let i = 0; i < 5; i++) {
      const options = page.locator('#opts button');
      const count = await options.count();
      await options.nth(i === 4 && count > 1 ? 1 : 0).click();
      if (i < 4) await page.getByRole('button', { name: 'AVANTI' }).click();
    }

    await page.getByRole('button', { name: 'CONTROLLO SICUREZZA' }).click();
    await expect(page.getByText('Prima la sicurezza.')).toBeVisible();

    await page.locator('#safetyChoice button').first().click();
    await page.getByRole('button', { name: 'VEDI LA PROPOSTA' }).click();
    await expect(page.getByText('Mobilità dolce dei polsi + respiro naturale.')).toBeVisible();

    for (let i = 0; i < 3; i++) {
      await page.getByRole('button', { name: 'PASSO SUCCESSIVO' }).click();
    }
    await expect(page.getByRole('button', { name: 'TERMINA PRATICA' })).toBeVisible();
    await page.getByRole('button', { name: 'TERMINA PRATICA' }).click();

    await page.getByRole('button', { name: 'Meglio', exact: true }).click();

    await page.locator('.nav button', { hasText: 'Dirette' }).click();
    await expect(page.getByText('Due percorsi. Nessuna confusione.')).toBeVisible();

    await page.locator('.nav button', { hasText: 'Mappa' }).click();
    await expect(page.getByText('Vedi tendenz,e, non diagnosi.')).toBeVisible();

    await page.locator('.nav button', { hasText: 'Regala' }).click();
    await expect(page.getByText('Regala uno spazio di pratica.')).toBeVisible();
    await page.getByRole('button', { name: 'VEDI ANTEPRIMA REGALO' }).click();
    await expect(page.getByText('Anteprima pronta.')).toBeVisible();

    await page.locator('.nav button', { hasText: 'Profilo' }).click();
    await expect(page.getByText('Il tuo spazio.')).toBeVisible();
    await page.getByRole('button', { name: 'VEDI I PIANI' }).click();
    await expect(page.getByText('Più continuità, non più sicurezza.')).toBeVisible();

    const overflowEnd = await page.evaluate(() =>
      document.documentElement.scrollWidth > document.documentElement.clientWidth + 2
    );
    expect(overflowEnd, 'No horizontal overflow after full flow').toBeFalsy();

    expect(consoleErrors, `Console must be clean: ${consoleErrors.join(' | ')}`).toEqual([]);

    await page.screenshot({ path: `test-results/dummy-${profile.name}.png`, fullPage: true });
    await page.close();
  });
}
