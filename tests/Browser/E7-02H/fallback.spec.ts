import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer;
let url: string;
test.setTimeout(60000);
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { server = await createServer({ configFile: 'tests/Browser/E7-02H/vite.config.ts', server: { host: '127.0.0.1', port: 0, strictPort: false } }); await server.listen(); url = new URL('/tests/Browser/E7-02H/fixture.html', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });
test('тестовая оценка явно отличается от оценки качества', async ({ page }) => {
  await page.goto(url);
  await expect(page.getByText('Тестовая оценка, качество не оценивалось: 3.0 / 5', { exact: false })).toBeVisible();
  await expect(page.getByText('Есть тестовые фиксированные оценки', { exact: false })).toBeVisible();
  await page.getByRole('button', { name: 'Открыть', exact: true }).first().click();
  await expect(page.getByText('Тестовая фиксированная оценка; качество диалога не оценивалось.')).toBeVisible();
  await mkdir('.local/screenshots/SP-E7-02H', { recursive: true });
  await page.screenshot({ path: '.local/screenshots/SP-E7-02H/groups.png', fullPage: true });
});
