import {test, expect} from '@playwright/test';
import {createServer, type ViteDevServer} from 'vite';
import {mkdir} from 'node:fs/promises';

let server: ViteDevServer; let url: string;
test.use({viewport: {width: 1440, height: 900}, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? {executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH} : {}});
test.beforeAll(async () => { server = await createServer({configFile: 'tests/Browser/E1-08H/vite.config.ts', cacheDir: '.local/runtime/vite-e108h', server: {host: '127.0.0.1', port: 0, strictPort: false}}); await server.listen(); url = new URL('/tests/Browser/E1-08H/fixture.html?screen=notifications', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });
test('приоритет времени и настройка сводки видны', async ({page}) => {
  await page.goto(url);
  await expect(page.getByText(/Личное время сводки имеет приоритет/)).toBeVisible();
  await page.getByRole('checkbox', {name: 'Сводка вместо каждого события: раз в день'}).check();
  await expect(page.locator('input[type=time]').last()).toHaveValue('09:00');
  await mkdir('.local/screenshots/SP-E1-08H', {recursive: true});
  await page.screenshot({path: '.local/screenshots/SP-E1-08H/timing.png', fullPage: true});
});

