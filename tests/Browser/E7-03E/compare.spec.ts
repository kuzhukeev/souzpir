import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';

let vite: ViteDevServer;
const fixture = 'http://127.0.0.1:5304/tests/Browser/E7-03E/fixture.html';
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { vite = await createServer({ configFile: 'tests/Browser/E7-03E/vite.config.ts' }); await vite.listen(); });
test.afterAll(async () => { await vite?.close(); });

test('у нового набора версия базы, у старого явная пометка', async ({ page }) => {
  await page.goto(fixture);
  await expect(page.getByRole('option', { name: /База v7/ })).toHaveCount(2);
  await expect(page.getByRole('option', { name: /Старый прогон — версия не сохранена/ })).toHaveCount(2);
  await expect(page.getByRole('table')).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/e7-03e/compare.png', fullPage: true });
});
