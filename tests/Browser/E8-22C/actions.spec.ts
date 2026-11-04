import { expect, test } from '@playwright/test';
import { mkdir } from 'node:fs/promises';
import { createServer, type ViteDevServer } from 'vite';

let server: ViteDevServer;
let fixture: string;
test.setTimeout(60000);
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => {
  server = await createServer({ configFile: 'tests/Browser/E8-22B/vite.config.ts' });
  await server.listen();
  fixture = new URL('/tests/Browser/E8-22B/fixture.html?history', server.resolvedUrls!.local[0]).href;
});
test.afterAll(async () => { await server?.close(); });

test('история показывает подтверждённые данные источника', async ({ page }) => {
  await page.route('**/vision/actions/effect', (route) => route.fulfill({ json: { items: [{ id: 'a', completed_by_name: 'Иван Петров', before: { visibility: 31 }, after: { visibility: 45 }, delta: 14 }] } }));
  await page.goto(fixture);
  const table = page.getByRole('table');
  await expect(table).toContainText('Иван Петров');
  await expect(table).toContainText('31 % → 45 %');
  await expect(table).toContainText('+14 п.п.');
  await expect(page.getByText('Сопоставимые замеры отдельных действий показаны ниже. Их изменение не доказывает причинную связь.')).toBeVisible();
  await mkdir('.local/screenshots/SP-E8-22C', { recursive: true });
  await page.screenshot({ path: '.local/screenshots/SP-E8-22C/history-sources.png', fullPage: true });
});

test('при отсутствии замеров видны пустые состояния', async ({ page }) => {
  await page.route('**/vision/actions/effect', (route) => route.fulfill({ json: { items: [{ id: 'a', completed_by_name: null, before: null, after: null, delta: null }] } }));
  await page.goto(fixture);
  await expect(page.getByRole('table')).toContainText('Нет сопоставимых замеров');
  await expect(page.getByRole('table')).toContainText('Рано судить');
  await page.screenshot({ path: '.local/screenshots/SP-E8-22C/history-empty.png', fullPage: true });
});

test('ошибку загрузки можно повторить', async ({ page }) => {
  let attempts = 0;
  await page.route('**/vision/actions/effect', (route) => {
    attempts++;
    return attempts === 1 ? route.fulfill({ status: 503 }) : route.fulfill({ json: { items: [] } });
  });
  await page.goto(fixture);
  await expect(page.getByRole('alert')).toContainText('Не удалось загрузить данные истории');
  await page.getByRole('button', { name: 'Повторить' }).click();
  await expect(page.getByRole('alert')).toHaveCount(0);
  expect(attempts).toBe(2);
});
