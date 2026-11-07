import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';

let server: ViteDevServer;
let url: string;
test.setTimeout(60000);
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { server = await createServer({ configFile: 'tests/Browser/E6-04C/vite.config.ts' }); await server.listen(); url = new URL('/tests/Browser/E6-04C/fixture.html', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });
test('настройки ожидания и переход к распределению', async ({ page }) => {
  await page.goto(url);
  await expect(page.getByRole('link', { name: 'Правила распределения →' })).toHaveAttribute('href', '/seller/settings');
  await page.getByLabel('Через сколько минут').fill('10');
  await page.getByText('Уведомление', { exact: true }).click();
  await page.getByText('Напоминание', { exact: true }).click();
  await expect(page.getByRole('switch', { name: 'Уведомление' })).toBeChecked();
  await expect(page.getByRole('switch', { name: 'Напоминание' })).toBeChecked();
  await page.getByLabel('Запасная фраза клиенту').fill('Менеджер ответит позже');
  await expect(page.getByText('Эти три настройки сохраняются', { exact: false })).toBeVisible();
  await mkdir('.local/screenshots/SP-E6-04C', { recursive: true });
  await page.screenshot({ path: '.local/screenshots/SP-E6-04C/handoff.png', fullPage: true });
  const post = page.waitForRequest(request => request.url().endsWith('/seller/scenario/handoff') && request.method() === 'POST');
  await page.route('**/seller/scenario/handoff', route => route.abort());
  await page.getByRole('button', { name: 'Сохранить', exact: true }).click();
  const payload = (await post).postDataJSON();
  expect(payload).toMatchObject({ timeout_minutes: 10, timeout_notification: true, timeout_reminder: true, timeout_fallback: 'Менеджер ответит позже' });
});
