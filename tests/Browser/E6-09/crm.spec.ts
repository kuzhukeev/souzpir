import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';

let server: ViteDevServer;
let fixture: string;
test.beforeAll(async () => {
  server = await createServer({ configFile: 'tests/Browser/E6-09/vite.config.ts', cacheDir: '.local/runtime/vite-e609-e706c', server: { host: '127.0.0.1', port: 0, strictPort: false } });
  await server.listen();
  fixture = new URL('/tests/Browser/E6-09/ui-fixture.html', server.resolvedUrls!.local[0]).href;
});
test.afterAll(async () => { await server?.close(); });

test.use({
  launchOptions: (process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || process.env.CRM_CHROMIUM_PATH)
    ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || process.env.CRM_CHROMIUM_PATH }
    : {},
});

test('страница показывает тестовую доставку и результат в журнале', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(fixture);
  await expect(page.getByRole('heading', { name: 'CRM-вебхук' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Настройка вебхука' })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Открыть настройки вебхуков' })).toBeVisible();
  await expect(page.getByText('Контакт получен').first()).toBeVisible();
  await expect(page.getByRole('button', { name: 'Отправить тестовое событие' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Проверить ошибку' })).toHaveCount(0);
  await expect(page.getByText('Ошибка (503)')).toBeVisible();
  await mkdir('.local/screenshots/sp-e6-09c', { recursive: true });
  await page.screenshot({ path: '.local/screenshots/sp-e6-09c/crm-1440.png', fullPage: true });
});
