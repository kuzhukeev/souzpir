import {expect, test} from '@playwright/test';
import {createServer, type ViteDevServer} from 'vite';
import {mkdir} from 'node:fs/promises';
let server: ViteDevServer; let root: string;
test.use({viewport: {width: 1440, height: 900}, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? {executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH} : {}});
test.beforeAll(async () => { server = await createServer({configFile: 'tests/Browser/E7-01H/vite.config.ts', cacheDir: '.local/runtime/vite-e701h', server: {host: '127.0.0.1', port: 0, strictPort: false}}); await server.listen(); root = server.resolvedUrls!.local[0]; });
test.afterAll(async () => { await server?.close(); });
test('переход из диалога и полное название замечания', async ({page}) => {
  await mkdir('.local/screenshots/SP-E7-01H', {recursive: true});
  await page.goto(new URL('/tests/Browser/E7-01H/dialog.html', root).href);
  await expect(page.getByRole('link', {name: 'Оценка диалога'})).toHaveAttribute('href', '/seller/evaluations/01ARZ3NDEKTSV4RRFFQ69G5FAV');
  await page.screenshot({path: '.local/screenshots/SP-E7-01H/dialog.png', fullPage: true});
  await page.goto(new URL('/tests/Browser/E7-01H/fixture.html', root).href);
  await expect(page.getByText('Ход 2 · Неверное значение поля')).toBeVisible();
  await page.screenshot({path: '.local/screenshots/SP-E7-01H/evaluation.png', fullPage: true});
});
