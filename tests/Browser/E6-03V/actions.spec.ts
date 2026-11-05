import {expect, test} from '@playwright/test';
import {createServer, type ViteDevServer} from 'vite';
import {mkdir} from 'node:fs/promises';
let server: ViteDevServer; let url: string;
test.use({viewport: {width: 1440, height: 900}, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? {executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH} : {}});
test.beforeAll(async () => { server = await createServer({configFile: 'tests/Browser/E6-03V/vite.config.ts', cacheDir: '.local/runtime/vite-e603v', server: {host: '127.0.0.1', port: 0, strictPort: false}}); await server.listen(); url = new URL('/tests/Browser/E6-03V/fixture.html', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });
test('показаны действия грубости и отказа', async ({page}) => {
  await page.goto(url);
  await expect(page.locator('article').filter({hasText: 'Грубость или раздражение'})).toContainText('Действие: Передать менеджеру');
  await expect(page.locator('article').filter({hasText: 'Не пишите мне'})).toContainText('Действие: Не беспокоить');
  await mkdir('.local/screenshots/SP-E6-03V', {recursive: true});
  await page.screenshot({path: '.local/screenshots/SP-E6-03V/actions.png', fullPage: true});
});
