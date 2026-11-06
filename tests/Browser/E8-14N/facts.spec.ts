import { test, expect } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer;
let url: string;
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { server = await createServer({ configFile: 'tests/Browser/E8-14N/vite.config.ts', server: { host: '127.0.0.1', port: 0, strictPort: false } }); await server.listen(); url = new URL('/tests/Browser/E8-14N/fixture.html', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });
test('факты добавляются только после выбора пользователя', async ({ page }) => {
  await page.goto(url + '?screen=facts');
  const confirm = page.getByRole('button', { name: 'Подтвердить факт: ISO 9001' });
  await expect(confirm).toBeVisible();
  await expect(page.getByRole('button', { name: 'Верно', exact: true })).toHaveCount(0);
  await mkdir('.local/screenshots/SP-E8-14N', { recursive: true });
  await page.screenshot({ path: '.local/screenshots/SP-E8-14N/facts.png', fullPage: true });
  await confirm.click();
  await expect(page.getByRole('button', { name: 'Верно', exact: true })).toHaveAttribute('aria-pressed', 'true');
  await expect(page.getByText('Данные сайта: Сертификаты: ISO 9001 · Источник: https://example.com/about')).toBeVisible();
});
test('исправление и отклонение требуют отдельного выбора', async ({ page }) => {
  await page.goto(url + '?screen=facts');
  await page.getByRole('button', { name: 'Добавить факт: ISO 9001' }).click();
  await expect(page.getByRole('button', { name: 'Верно', exact: true })).toHaveAttribute('aria-pressed', 'false');
});
test('отклонение сохраняет решение', async ({ page }) => {
  await page.goto(url + '?screen=facts');
  await page.getByRole('button', { name: 'Не про нас: ISO 9001' }).click();
  await expect(page.getByRole('button', { name: 'Не про нас', exact: true })).toHaveAttribute('aria-pressed', 'true');
});
