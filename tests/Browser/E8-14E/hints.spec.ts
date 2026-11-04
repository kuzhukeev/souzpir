import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';

let server: ViteDevServer;
let url: string;
test.setTimeout(60000);
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => {
  server = await createServer({ configFile: 'tests/Browser/E8-14E/vite.config.ts' });
  await server.listen();
  url = new URL('/tests/Browser/E8-14E/fixture.html', server.resolvedUrls!.local[0]).href;
});
test.afterAll(async () => { await server?.close(); });
test('выбор подсказки аудитории и факта сайта', async ({ page }) => {
  await mkdir('.local/screenshots/SP-E8-14E', { recursive: true });
  await page.goto(`${url}?screen=hints`);
  const hint = page.getByRole('button', { name: /Тип покупателя: Организаторы/ });
  await expect(hint).toBeVisible();
  await hint.click();
  await expect(hint).toBeDisabled();
  await expect(page.getByRole('button', { name: 'Удалить: Организаторы' })).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/SP-E8-14E/audience.png', fullPage: true });
  await page.goto(`${url}?screen=facts`);
  await page.getByRole('button', { name: 'Добавить факт: Москва, Пушкина 1' }).click();
  await expect(page.getByText('Москва, Пушкина 1', { exact: true })).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/SP-E8-14E/facts.png', fullPage: true });
});
