import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer; let url: string;
test.setTimeout(60000);
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { server = await createServer({ configFile: 'tests/Browser/E4-10M/vite.config.ts', server: { host: '127.0.0.1', port: 0 } }); await server.listen(); url = new URL('/tests/Browser/E4-10M/fixture.html', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });
test('карточка редактируется и передаёт свои данные', async ({ page }) => {
 await page.goto(url);
 await expect(page.getByRole('textbox', { name: 'Ответ образца 1' })).toBeVisible();
 await mkdir('.local/screenshots/SP-E4-10M', { recursive: true });
 await page.screenshot({ path: '.local/screenshots/SP-E4-10M/cards.png', fullPage: true });
 await page.getByRole('textbox', { name: 'Ответ образца 1' }).fill('Подскажите город доставки');
 await page.getByRole('combobox', { name: 'Тег образца 1' }).selectOption('open_greeting');
 const post = page.waitForRequest(request => request.url().endsWith('/seller/knowledge/fewshot') && request.method() === 'POST');
 await page.route('**/seller/knowledge/fewshot', route => route.abort());
 await page.getByRole('button', { name: 'Сохранить образец 1' }).click();
 expect((await post).postDataJSON()).toMatchObject({ version: 3, index: 0, bucket: 'examples', tag: 'open_greeting', text: 'Подскажите город доставки' });
 await expect(page.getByRole('alert').filter({ hasText: 'Нет связи' })).toBeVisible();
});

test('пустой список предлагает добавить образец', async ({ page }) => {
 await page.goto(url + '?empty=1');
 await expect(page.getByText('Образцов нет. Добавьте образец ниже или выберите ответ из диалога.')).toBeVisible();
 await expect(page.getByRole('heading', { name: 'Добавить образец' })).toBeVisible();
});


test('filters cards and keeps controls reachable on narrow screens', async ({ page }) => {
 for (const width of [1440, 1024, 390]) {
  await page.setViewportSize({ width, height: 900 });
  await page.goto(url);
  await page.getByRole('button', { name: 'price', exact: true }).click();
  await expect(page.locator('li').filter({ has: page.locator('textarea') })).toHaveCount(1);
  await expect(page.getByRole('button', { name: 'price', exact: true })).toHaveAttribute('aria-pressed', 'true');
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
 }
 await page.setViewportSize({ width: 1440, height: 900 });
 await page.goto(url + '?empty=1');
 await page.screenshot({ path: '.local/screenshots/SP-E4-10M/empty.png', fullPage: true });
});
