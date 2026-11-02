import { expect, test } from '@playwright/test';
import { spawn, type ChildProcess } from 'node:child_process';

let server: ChildProcess;
const fixture = 'http://127.0.0.1:5286/tests/Browser/E4-08/fixture.html';
test.beforeAll(async () => {
  server = spawn(process.execPath, ['node_modules/vite/bin/vite.js', '--config', 'tests/Browser/E4-08/vite.config.ts'], { stdio: 'ignore' });
  for (let attempt = 0; attempt < 60; attempt++) {
    try { if ((await fetch(fixture)).ok) return; } catch { /* Wait for Vite. */ }
    if (server.exitCode !== null) throw new Error('Vite fixture exited before startup');
    await new Promise(resolve => setTimeout(resolve, 500));
  }
  throw new Error('Vite fixture did not start');
});
test.afterAll(() => { server?.kill(); });
test.use({ launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });

test('плохой образец и выбор реплики менеджера', async ({ page }) => {
  await page.route('**/seller/knowledge/fewshot/dialogs?*', route => route.fulfill({ json: { dialogs: [{ id: '01J00000000000000000000005', name: 'Мария Соколова' }] } }));
  await page.route('**/seller/knowledge/fewshot/dialogs/01J00000000000000000000005', route => route.fulfill({ json: { messages: [{ id: '01J00000000000000000000006', text: 'Уточню условия поставки.' }] } }));
  for (const width of [1440, 390]) {
    await page.setViewportSize({ width, height: 900 });
    await page.goto(fixture);
    await expect(page.getByRole('heading', { name: 'Плохие образцы' })).toBeVisible();
    await expect(page.getByText('Доставка всегда бесплатна.')).toBeVisible();
    if (width === 1440) await page.screenshot({ path: '.local/screenshots/sp-e4-08b/bad-1440.png', fullPage: true });
    await page.getByRole('button', { name: 'Добавить из диалога' }).click();
    await page.getByRole('button', { name: 'Найти' }).click();
    await page.getByRole('radio', { name: /Мария Соколова/ }).check();
    await expect(page.getByRole('radio', { name: 'Уточню условия поставки.' })).toBeVisible();
    await page.getByRole('radio', { name: 'Уточню условия поставки.' }).check();
    await expect(page.getByRole('button', { name: 'Добавить образец' })).toBeEnabled();
    const size = await page.evaluate(() => ({ scroll: document.documentElement.scrollWidth, viewport: innerWidth }));
    expect(size.scroll).toBeLessThanOrEqual(size.viewport);
    if (width === 1440) { await page.evaluate(() => window.scrollBy(0, 120)); await page.screenshot({ path: '.local/screenshots/sp-e4-08b/from-dialog-1440.png' }); }
  }
});
