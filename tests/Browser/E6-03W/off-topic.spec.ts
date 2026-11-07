import {expect, test} from '@playwright/test';
import {createServer, type ViteDevServer} from 'vite';
import {mkdir} from 'node:fs/promises';
let server: ViteDevServer; let url: string;
test.use({viewport: {width: 1440, height: 900}, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? {executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH} : {}});
test.beforeAll(async () => { server = await createServer({configFile: 'tests/Browser/E6-03W/vite.config.ts', cacheDir: '.local/runtime/vite-e603w', server: {host: '127.0.0.1', port: 0, strictPort: false}}); await server.listen(); url = new URL('/tests/Browser/E6-03W/fixture.html', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });
test('поле «Коротких ответов» сохраняет значение из черновика', async ({page}) => {
  await page.goto(url);
  const section = page.locator('section').filter({hasText: 'Вне темы и негатив'});
  const field = section.getByLabel('Коротких ответов');
  await expect(field).toHaveValue('2');
  await field.fill('4');
  await expect(field).toHaveValue('4');
  await mkdir('.local/screenshots/SP-E6-03W', {recursive: true});
  await page.screenshot({path: '.local/screenshots/SP-E6-03W/off-topic.png', fullPage: true});
});
