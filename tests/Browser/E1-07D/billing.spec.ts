import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';

let server: ViteDevServer;
let base: string;

test.beforeAll(async () => {
  server = await createServer({ configFile: 'tests/Browser/E1-07D/vite.config.ts' });
  await server.listen();
  const address = server.httpServer!.address();
  if (!address || typeof address === 'string') throw new Error('Vite address unavailable');
  base = `http://127.0.0.1:${address.port}/tests/Browser/E1-07D/fixture.html`;
});

test.afterAll(async () => { await server?.close(); });
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: { executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe' } });

test('plan shows used limits', async ({ page }) => {
  await page.goto(base);
  await expect(page.getByRole('progressbar', { name: 'Лимит 80' })).toBeVisible();
  await expect(page.getByText('80 / 100')).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/SP-E1-07D/plan.png', fullPage: true });
});

test('invoices show an empty table', async ({ page }) => {
  await page.goto(`${base}?invoices`);
  await expect(page.getByRole('columnheader', { name: 'Номер' })).toBeVisible();
  await expect(page.getByRole('columnheader', { name: 'PDF' })).toBeVisible();
  await expect(page.getByText('Счетов пока нет')).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/SP-E1-07D/invoices.png', fullPage: true });
});

test('usage shows daily data', async ({ page }) => {
  await page.goto(`${base}?usage`);
  await expect(page.getByRole('img', { name: 'Столбцы использования по дням' })).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/SP-E1-07D/usage.png', fullPage: true });
});
