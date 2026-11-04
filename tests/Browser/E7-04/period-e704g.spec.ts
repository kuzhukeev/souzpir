import { test, expect } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer;
let url: string;
test.setTimeout(60000);
test.use({
  viewport: { width: 1440, height: 900 },
  launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH
    ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH }
    : {},
});
test.beforeAll(async () => {
  server = await createServer({
    configFile: 'tests/Browser/E7-04/vite.config.ts',
    server: { host: '127.0.0.1', port: 0 },
  });
  await server.listen();
  url = new URL('/tests/Browser/E7-04/period-fixture.html', server.resolvedUrls!.local[0]).href;
});
test.afterAll(async () => {
  await server?.close();
});
test('сравнение текущих состояний периодов', async ({ page }) => {
  await page.goto(url);
  const section = page.getByRole('region', { name: 'Сравнение периодов' });
  await expect(section.getByRole('row', { name: 'Контакт 2 1 -1' })).toBeVisible();
  await expect(section.getByText(/текущие состояния диалогов/)).toBeVisible();
  await mkdir('.local/screenshots/sp-e7-04g', { recursive: true });
  await section.screenshot({ path: '.local/screenshots/sp-e7-04g/comparison.png' });
});

test('вкладки показывают выбранный раздел и сравнение выключается', async ({ page }) => {
  await page.goto(url);
  await page.getByRole('checkbox', { name: 'Сравнить с предыдущим периодом' }).uncheck();
  await expect(page.getByRole('region', { name: 'Сравнение периодов' })).toBeHidden();
  await page.getByRole('button', { name: 'Диалоги', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Аналитика диалогов' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Воронка диалогов' })).toBeHidden();
  await page.getByRole('button', { name: 'Темы и вопросы', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Темы и вопросы', exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Отчёты', exact: true }).click();
  await expect(page.getByLabel('Тип отчёта')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Менеджеры', exact: true })).toHaveCount(0);
  for (const width of [1440, 1024, 390]) {
    await page.setViewportSize({ width, height: 900 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(
      true,
    );
  }
});
