import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer;
let url: string;
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { server = await createServer({ configFile: 'tests/Browser/E8-16P/vite.config.ts', server: { host: '127.0.0.1', port: 0, strictPort: false } }); await server.listen(); url = new URL('/tests/Browser/E8-16P/fixture.html?page=report', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });
test('history chart shows every measurement with date and value', async ({ page }) => {
 await page.goto(url);
 const chart = page.getByRole('img', { name: 'История видимости по датам' });
 await expect(chart).toBeVisible();
 await expect(chart.locator('circle')).toHaveCount(3);
 const values = page.getByRole('list', { name: 'Значения видимости' });
 await expect(values).toContainText('01.11.2026: 15%');
 await expect(values).toContainText('02.11.2026: 30%');
 await expect(values).toContainText('04.11.2026: 34%');
 await mkdir('.local/screenshots/SP-E8-16P', { recursive: true });
 await page.screenshot({ path: '.local/screenshots/SP-E8-16P/history.png', fullPage: true });
 await page.goto(url + '&single=1');
 await expect(page.getByText('Нет двух сопоставимых подтверждённых замеров.')).toBeVisible();
});


test('model values and report layout work across widths', async ({ page }) => {
 for (const width of [1440, 1024, 390]) {
  await page.setViewportSize({width, height: 900});
  await page.goto(url);
  const models = page.locator('#models');
  await expect(models).toContainText('ChatGPT');
  await expect(models).toContainText('34 %');
  await expect(models).toContainText('Claude');
  await expect(models.locator('li').nth(1).locator('[aria-hidden="true"]')).toHaveCount(0);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
 }
});


test('chart renders in all themes', async ({ page }) => {
 for (const theme of ['neutral', 'confectionery', 'pyro']) {
  await page.goto(url + '&theme=' + theme);
  await expect(page.locator('html')).toHaveAttribute('data-theme', theme);
  await expect(page.locator('svg[role="img"]')).toBeVisible();
 }
});
