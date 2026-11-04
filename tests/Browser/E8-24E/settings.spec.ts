import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';

let server: ViteDevServer;
let fixture: string;
test.setTimeout(60000);
test.use({ viewport: { width: 1440, height: 900 }, colorScheme: 'light', launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => {
  server = await createServer({ configFile: 'tests/Browser/E8-24E/vite.config.ts', server: { host: '127.0.0.1', port: 0, strictPort: false } });
  await server.listen();
  fixture = new URL('/tests/Browser/E8-24E/fixture.html', server.resolvedUrls!.local[0]).href;
});
test.afterAll(async () => { await server?.close(); });

test('модели сохраняются при выключенном расписании', async ({ page }) => {
  await page.goto(fixture);
  const save = page.getByRole('button', { name: 'Сохранить модели' });
  await expect(save).toBeEnabled();
  await page.getByRole('checkbox', { name: /Claude/ }).check();
  await expect(page.getByText('Выбрано 2 из 2')).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/SP-E8-24E/models-disabled-schedule.png', fullPage: true });
  const request = page.waitForRequest(req => req.url().endsWith('/vision/settings/models') && req.method() === 'PUT');
  await save.click();
  expect(JSON.parse((await request).postData() ?? '{}').models).toContain('anthropic/claude-sonnet-5.5');
});
