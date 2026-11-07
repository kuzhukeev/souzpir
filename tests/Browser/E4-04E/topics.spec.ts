import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
let server: ViteDevServer; let fixture: string;
test.beforeAll(async () => { server = await createServer({ configFile: 'tests/Browser/E4-04E/vite.config.ts', server: { host: '127.0.0.1', port: 0 } }); await server.listen(); fixture = new URL('/tests/Browser/E4-04E/fixture.html', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });
test.use({ launchOptions: { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } });
test('поиск примеров, сортировка и мобильный список', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 }); await page.goto(fixture);
  await page.getByLabel('Сортировка').selectOption('frequency');
  await expect(page.locator('tbody tr').first()).toContainText('Оплата');
  await page.getByLabel('Поиск по темам').fill('оплатить');
  await expect(page.locator('tbody tr')).toHaveCount(1);
  await expect(page.locator('tbody tr')).toContainText('Оплата');
  await page.getByLabel('Поиск по темам').fill('');
  await page.screenshot({ path: '.local/screenshots/sp-e4-04e/topics.png', fullPage: true });
  await page.setViewportSize({ width: 390, height: 844 });
  await expect(page.locator('tbody tr').first()).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
});
