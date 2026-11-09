import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';

let server: ViteDevServer;
let url: string;
test.setTimeout(60000);
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { server = await createServer({ configFile: 'tests/Browser/E6-04D/vite.config.ts' }); await server.listen(); url = new URL('/tests/Browser/E6-04D/fixture.html', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });
test('действия показывают отсутствие счётчика и ведут к настройкам', async ({ page }) => {
  await page.goto(url);
  await expect(page.getByRole('cell',{name:'нет данных',exact:true})).toHaveCount(3);
  await expect(page.getByRole('link', { name: 'Настроить →' }).nth(0)).toHaveAttribute('href', '#delivery-rules');
  await expect(page.getByRole('link', { name: 'Настроить →' }).nth(1)).toHaveAttribute('href', '#requisites');
  await expect(page.getByRole('link', { name: 'Настроить →' }).nth(2)).toHaveAttribute('href', '/seller/scenario/handoff');
  await expect(page.getByText('Правила доставки используются при расчёте.', { exact: false })).toBeVisible();
  await mkdir('.local/screenshots/SP-E6-04D', { recursive: true });
  await page.screenshot({ path: '.local/screenshots/SP-E6-04D/tools.png', fullPage: true });
});
