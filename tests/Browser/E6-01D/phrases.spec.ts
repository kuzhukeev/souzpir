import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';

let server: ViteDevServer;
let fixture: string;
test.setTimeout(60000);
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => {
  server = await createServer({ configFile: 'tests/Browser/E6-01D/vite.config.ts', server: { host: '127.0.0.1', port: 0, strictPort: false } });
  await server.listen();
  fixture = new URL('/tests/Browser/E6-01C/fixture.html', server.resolvedUrls!.local[0]).href;
});
test.afterAll(async () => { await server?.close(); });

test('редактор показывает сохранённую служебную фразу', async ({ page }) => {
  await page.goto(fixture);
  await page.getByRole('tab', { name: 'Служебные фразы' }).click();
  await expect(page.getByLabel('При ошибке')).toHaveValue('Секунду, уточню пару моментов и вернусь к вам сегодня.');
  await page.screenshot({ path: '.local/screenshots/SP-E6-01D/phrases.png', fullPage: true });
});
