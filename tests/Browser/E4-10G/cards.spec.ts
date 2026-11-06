import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer; let url: string;
test.setTimeout(60000);
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { server = await createServer({ configFile: 'tests/Browser/E4-10G/vite.config.ts' }); await server.listen(); url = new URL('/tests/Browser/E4-10G/fixture.html', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });
test('карточка редактируется и передаёт свои данные', async ({ page }) => {
 await page.goto(url);
 await expect(page.getByRole('textbox', { name: 'Ответ образца 1' })).toBeVisible();
 await mkdir('.local/screenshots/SP-E4-10G', { recursive: true });
 await page.screenshot({ path: '.local/screenshots/SP-E4-10G/cards.png', fullPage: true });
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
