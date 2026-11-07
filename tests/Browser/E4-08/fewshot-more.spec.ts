import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';

let server: ViteDevServer;
let fixture: string;
test.beforeAll(async () => {
  server = await createServer({ configFile: 'tests/Browser/E4-08/vite.config.ts', server: { host: '127.0.0.1', port: 0 } });
  await server.listen();
  fixture = new URL('/tests/Browser/E4-08/fixture.html', server.resolvedUrls!.local[0]).href;
});
test.afterAll(async () => { await server?.close(); });
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
    await page.locator('button[aria-haspopup="dialog"]').filter({ hasText: 'Добавить из диалога' }).click();
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
