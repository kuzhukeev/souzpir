import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer;
let url: string;
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { server = await createServer({ configFile: 'tests/Browser/E4-10K/vite.config.ts', server: { host: '127.0.0.1', port: 0 } }); await server.listen(); url = new URL('/tests/Browser/E4-10K/fixture.html', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });
test('initial loading, error, retry and empty state', async ({ page }) => {
 let count = 0;
 let release!: () => void;
 const pending = new Promise<void>(resolve => { release = resolve; });
 await page.route('**/tests/Browser/E4-10K/fixture.html', async route => {
   if (!route.request().headers()['x-inertia']) return route.continue();
   count++;
   if (count === 1) await pending;
   const payload = count === 1
     ? { component: 'Fixture', props: {}, rescuedProps: ['fewshotData'], url: new URL(url).pathname, version: 'fixture' }
     : { component: 'Fixture', props: { fewshotData: { draft: { version: 1 }, fewshot: { examples: [], disabled_examples: [], bad_examples: [] }, availableTags: ['ask_city'], maxWords: null } }, url: new URL(url).pathname, version: 'fixture' };
   return route.fulfill({ status: 200, headers: { 'X-Inertia': 'true' }, json: payload });
 });
 await page.goto(url);
 await expect(page.getByRole('status').filter({ hasText: 'Загружаем образцы речи' })).toBeAttached();
 await mkdir('.local/screenshots/SP-E4-10K', { recursive: true });
 await page.screenshot({ path: '.local/screenshots/SP-E4-10K/loading.png', fullPage: true });
 release();
 await expect(page.getByRole('alert')).toContainText('Не удалось загрузить образцы речи');
 await page.screenshot({ path: '.local/screenshots/SP-E4-10K/error.png', fullPage: true });
 await page.getByRole('button', { name: 'Повторить' }).click();
 await expect(page.getByText('Образцов нет. Добавьте образец ниже или выберите ответ из диалога.')).toBeVisible();
 await page.getByRole('heading', { name: 'Образцов речи пока нет' }).locator('..').getByRole('button', { name: 'Добавить из диалога' }).click();
 await expect(page.getByRole('dialog')).toBeVisible();
 await page.getByRole('button', { name: 'Закрыть' }).click();
 await mkdir('.local/screenshots/SP-E4-10K', { recursive: true });
 await page.screenshot({ path: '.local/screenshots/SP-E4-10K/empty.png', fullPage: true });
 expect(count).toBe(2);
});

test('network failure allows retry', async ({ page }) => {
 let count = 0;
 await page.route('**/tests/Browser/E4-10K/fixture.html', async route => {
   if (!route.request().headers()['x-inertia']) return route.continue();
   count++;
   if (count === 1) return route.abort('failed');
   return route.fulfill({ status: 200, headers: { 'X-Inertia': 'true' }, json: { component: 'Fixture', props: { fewshotData: { draft: null, fewshot: null, availableTags: [], maxWords: null } }, url: new URL(url).pathname, version: 'fixture' } });
 });
 await page.goto(url);
 await expect(page.getByRole('alert')).toContainText('Не удалось загрузить образцы речи');
 await page.getByRole('button', { name: 'Повторить' }).click();
 await expect(page.getByRole('link', { name: 'создайте черновик базы знаний' })).toBeVisible();
 expect(count).toBe(2);
});
