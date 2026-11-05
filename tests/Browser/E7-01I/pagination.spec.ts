import {expect, test} from '@playwright/test';
import {createServer, type ViteDevServer} from 'vite';
import {mkdir} from 'node:fs/promises';
let server: ViteDevServer; let url: string;
test.use({viewport: {width: 1440, height: 900}, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? {executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH} : {}});
test.beforeAll(async () => { server = await createServer({configFile: 'tests/Browser/E7-01I/vite.config.ts', cacheDir: '.local/runtime/vite-e701i', server: {host: '127.0.0.1', port: 0, strictPort: false}}); await server.listen(); url = new URL('/tests/Browser/E7-01I/fixture.html', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });
test('страница докладов запрашивается с сервера и ошибка видна', async ({page}) => {
  await page.goto(url);
  await expect(page.getByText('1 / 2', {exact: true})).toBeVisible();
  await mkdir('.local/screenshots/SP-E7-01I', {recursive: true});
  await page.screenshot({path: '.local/screenshots/SP-E7-01I/reports.png', fullPage: true});
  let requested = '';
  await page.route('**/seller/quality?*', async route => { requested = route.request().url(); await route.abort(); });
  await page.getByRole('button', {name: 'Далее'}).click();
  await expect.poll(() => requested).toContain('page=2');
  await expect(page.getByRole('alert')).toContainText('Не удалось связаться с сервером.');
});
test('замечание ведёт к исходному диалогу', async ({page}) => {
  await page.goto(`${url}?day`);
  await page.locator('summary').first().click();
  await expect(page.getByRole('link', {name: 'Исправить'})).toHaveAttribute('href', /\/seller\/dialogs\/01ARZ3NDEKTSV4RRFFQ69G5FAV\?report_day=\d{4}-\d{2}-\d{2}/);
  await mkdir('.local/screenshots/SP-E7-01I', {recursive: true});
  await page.screenshot({path: '.local/screenshots/SP-E7-01I/day.png', fullPage: true});
});
