import {expect, test} from '@playwright/test';
import {createServer, type ViteDevServer} from 'vite';
import {mkdir} from 'node:fs/promises';
let server: ViteDevServer; let url: string;
test.use({viewport: {width: 1440, height: 900}, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? {executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH} : {}});
test.beforeAll(async () => {server = await createServer({configFile: 'tests/Browser/E1-13/vite.config.ts', cacheDir: '.local/runtime/vite-e113', server: {host: '127.0.0.1', port: 0, strictPort: false}}); await server.listen(); url = new URL('/tests/Browser/E1-13/fixture.html', server.resolvedUrls!.local[0]).href;});
test.afterAll(async () => {await server?.close();});
test('пример условий и предупреждение о лимите', async ({page}) => {
  await mkdir('.local/screenshots/SP-E1-13', {recursive: true});
  await page.goto(url);
  await expect(page.getByText('Пример условий')).toBeVisible();
  await expect(page.getByText('Использовано не менее 80% лимита.')).toBeVisible();
  await page.screenshot({path: '.local/screenshots/SP-E1-13/current.png', fullPage: true});
  await page.goto(`${url}?plans`);
  await expect(page.getByText('Пример условий')).toBeVisible();
  await expect(page.getByText(/9\s?900 ₽/)).toBeVisible();
  await expect(page.getByText(/24\s?900 ₽/)).toBeVisible();
  await page.screenshot({path: '.local/screenshots/SP-E1-13/plans.png', fullPage: true});
});
