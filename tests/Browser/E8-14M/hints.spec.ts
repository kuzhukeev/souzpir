import { test, expect } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer;
let url: string;
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { server = await createServer({ configFile: 'tests/Browser/E8-14M/vite.config.ts', server: { host: '127.0.0.1', port: 0, strictPort: false } }); await server.listen(); url = new URL('/tests/Browser/E8-14M/fixture.html', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });
test('источник факта указан, добавление и подтверждение выполняет пользователь', async ({ page }) => {
  await page.goto(url + '?screen=facts');
  await expect(page.getByText('Данные сайта: Сертификаты: ISO 9001 · Источник: https://example.com/about')).toBeVisible();
  const add = page.getByRole('button', { name: 'Добавить факт: ISO 9001' });
  await expect(add).toBeEnabled();
  await add.click();
  await expect(add).toBeDisabled();
  await expect(page.getByRole('button', { name: 'Верно' })).toBeVisible();
  await mkdir('.local/screenshots/SP-E8-14M', { recursive: true });
  await page.screenshot({ path: '.local/screenshots/SP-E8-14M/facts.png', fullPage: true });
});
