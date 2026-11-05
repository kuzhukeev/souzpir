import { test, expect } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';

let server: ViteDevServer;
let url: string;
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => {
  server = await createServer({ configFile: 'tests/Browser/E7-02L/vite.config.ts', server: { host: '127.0.0.1', port: 0, strictPort: false } });
  await server.listen();
  url = new URL('/tests/Browser/E7-02L/fixture.html', server.resolvedUrls!.local[0]).href;
});
test.afterAll(async () => { await server?.close(); });
test('выбор черновика и отправка прогона', async ({ page }) => {
  await page.goto(url);
  await page.getByLabel('Новый покупатель').check();
  await page.getByLabel('Версия знаний').selectOption('draft');
  await mkdir('.local/screenshots/sp-e7-02l', { recursive: true });
  await page.screenshot({ path: '.local/screenshots/sp-e7-02l/draft.png', fullPage: true });
  let payload: unknown = null;
  await page.route('**/seller/quality/runs', async route => { payload = route.request().postDataJSON(); await route.abort(); });
  await page.getByRole('button', { name: 'Запустить', exact: true }).click();
  await expect.poll(() => payload).toEqual({ scenarios: ['one'], knowledge: 'draft', model: 'fake' });
});
