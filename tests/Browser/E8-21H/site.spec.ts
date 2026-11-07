import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer;
let url: string;
test.use({
  viewport: { width: 1440, height: 900 },
  launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH
    ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH }
    : {},
});
test.beforeAll(async () => {
  server = await createServer({
    configFile: 'tests/Browser/E8-21H/vite.config.ts',
    server: { host: '127.0.0.1', port: 0 },
  });
  await server.listen();
  url = new URL('/tests/Browser/E8-21H/fixture.html', server.resolvedUrls!.local[0]).href;
});
test.afterAll(async () => {
  await server?.close();
});
test('важность и примеры на основе данных проверки', async ({ page }) => {
  await page.goto(url);
  await expect(page.getByText('Скорость сайта', { exact: true })).toHaveCount(0);
  await expect(
    page.getByText(
      'Важность определяется весом критерия в расчёте готовности; проверки инспектора имеют одинаковый вес.',
    ),
  ).toBeVisible();
  await page.getByText('Структурированные данные', { exact: true }).click();
  await expect(
    page.getByText(
      'Важность по весу: 5 из 100 баллов. Все критерии инспектора имеют одинаковый вес.',
    ),
  ).toBeVisible();
  await page.getByText('Для разработчика').first().click();
  await expect(
    page.getByText('Пример структуры; подставьте реальные сведения вашей компании.').first(),
  ).toBeVisible();
  await mkdir('.local/screenshots/sp-e8-21h', { recursive: true });
  await page.screenshot({ path: '.local/screenshots/sp-e8-21h/audit.png', fullPage: true });
});
