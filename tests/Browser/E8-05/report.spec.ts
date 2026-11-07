import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';

let server: ViteDevServer;
let url: string;
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { server = await createServer({ configFile: 'tests/Browser/E8-05/vite.config.ts', server: { host: '127.0.0.1', port: 0 } }); await server.listen(); url = new URL('/tests/Browser/E8-05/report-fixture.html', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });

test('внутренний отчёт и пустое состояние', async ({ page }) => {
  await page.goto(url);
  await expect(page.getByRole('heading', { name: 'Отчёт о видимости в нейросетях' })).toBeVisible();
  await expect(page.getByText('Видимость по моделям')).toBeVisible();
  await expect(page.getByText('Недостаточно данных').first()).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/sp-e8-05/report-1440.png', fullPage: true });
  await page.goto(`${url}?empty`);
  await expect(page.getByText('Пока нет подтверждённого замера.')).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/sp-e8-05/empty-1440.png', fullPage: true });
});
