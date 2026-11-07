import { test, expect } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer;
let url: string;
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { server = await createServer({ configFile: 'tests/Browser/E8-15J/vite.config.ts', server: { host: '127.0.0.1', port: 0, strictPort: false } }); await server.listen(); url = new URL('/tests/Browser/E8-15J/fixture.html', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });
test('расчёт на расписании и выбор периода', async ({ page }) => {
  await page.goto(url);
  await page.getByRole('button', { name: 'Расписание', exact: true }).click();
  await expect(page.getByText('Расчёт слотов за текущий месяц по текущему набору вопросов: 5 автоматических запусков · 25 слотов.')).toBeVisible();
  await mkdir('.local/screenshots/SP-E8-15J', { recursive: true });
  await page.screenshot({ path: '.local/screenshots/SP-E8-15J/schedule.png', fullPage: true });
  await page.getByRole('button', { name: 'Подробнее о слотах' }).click();
  await expect(page.getByText('За выбранный период: автоматических запусков: 5, слотов: 25.')).toBeVisible();
  await page.getByLabel('Начало периода оценки').fill('2026-11-09');
  await page.route('**/vision/settings?*', route => route.abort());
  const request = page.waitForRequest(request => request.url().includes('estimate_from=2026-11-09'));
  await page.getByRole('button', { name: 'Рассчитать период' }).click();
  expect((await request).url()).toContain('estimate_to=2026-11-30');
});
test('при отсутствии истории показывается отсутствие данных', async ({ page }) => {
  await page.goto(url + '?empty');
  await page.getByRole('button', { name: 'Расписание', exact: true }).click();
  await expect(page.getByText('Расчёт слотов за текущий месяц по текущему набору вопросов: нет данных для оценки.')).toBeVisible();
});
