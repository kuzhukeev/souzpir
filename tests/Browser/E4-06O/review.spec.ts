import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer; let url: string;
test.setTimeout(60000);
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { server = await createServer({ configFile: 'tests/Browser/E4-06O/vite.config.ts', cacheDir: '.local/runtime/vite-e406o', server: { host: '127.0.0.1', port: 0 } }); await server.listen(); url = new URL('/tests/Browser/E4-06O/fixture.html?imports', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });
test('пары выровнены, группа обозначена и действия доступны с клавиатуры', async ({ page }) => {
 await page.route('**/seller/knowledge/imports/preview', route => route.fulfill({ json: { proposals: ['Условия доставки', 'Город получения и очень длинное описание, которое занимает несколько строк '.repeat(4)], document_id: 'new', sheets: [], document: { id: 'new', name: 'facts.txt', type: 'txt', pages: null, status: 'previewed', fragments_count: 2, has_source: true, created_at: '2026-11-01' } } }));
 for (const [theme, width] of [['neutral', 1440], ['confectionery', 1024], ['pyro', 390]] as const) {
  await page.setViewportSize({ width, height: 900 }); await page.goto(url + '&theme=' + theme);
  await expect(page.locator('html')).toHaveAttribute('data-theme', theme);
  await page.getByLabel('Файл', { exact: true }).setInputFiles({ name: 'facts.txt', mimeType: 'text/plain', buffer: Buffer.from('текст') });
  await page.getByRole('button', { name: 'Посмотреть предложения' }).click();
  await expect(page.getByRole('heading', { name: 'Сопоставление фактов' })).toBeFocused();
  await page.getByLabel('Группа фактов для всего импорта').selectOption('Доставка');
  await expect(page.getByText('Группа: Доставка')).toHaveCount(2);
  const pair = page.getByRole('list', { name: 'Сопоставление фрагментов и фактов' }).locator(':scope > li').first();
  if (width >= 1024) { const boxes = await pair.locator(':scope > div').evaluateAll(nodes => nodes.map(node => node.getBoundingClientRect().top)); expect(Math.abs(boxes[0] - boxes[1])).toBeLessThan(1); }
  const fragment = page.getByRole('button', { name: 'Фрагмент 1: Условия доставки' }); await fragment.focus(); await page.keyboard.press('Enter'); await expect(fragment).toHaveAttribute('aria-pressed', 'true');
  await page.getByLabel('Принять факт 1').focus(); await page.keyboard.press('Space'); await expect(page.getByLabel('Принять факт 1')).toBeChecked();
  await page.getByRole('button', { name: 'Изменить', exact: true }).first().click(); await page.getByLabel('Текст факта').fill('Изменено'); await page.getByRole('button', { name: 'Готово' }).click();
  await expect(page.getByText('Изменено', { exact: true })).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true);
  await mkdir('.local/screenshots/SP-E4-06O', { recursive: true }); await page.screenshot({ path: `.local/screenshots/SP-E4-06O/${theme}.png`, fullPage: true });
  await page.getByRole('button', { name: 'Отклонить', exact: true }).first().click(); await expect(page.getByRole('heading', { name: 'Сопоставление фактов' })).toBeFocused();
 }
});

test('сообщает об ошибке и не предлагает сохранение без черновика', async ({ page }) => {
 await page.route('**/seller/knowledge/imports/preview', async route => {
  if (route.request().url().includes('unused')) return route.continue();
  await route.fulfill({ status: 422, json: { message: 'Файл не удалось прочитать' } });
 });
 await page.goto(url); await page.getByLabel('Файл', { exact: true }).setInputFiles({ name: 'bad.txt', mimeType: 'text/plain', buffer: Buffer.from('bad') });
 await page.getByRole('button', { name: 'Посмотреть предложения' }).click(); await expect(page.getByRole('alert')).toContainText('Файл не удалось прочитать');
 await page.unrouteAll();
 await page.route('**/seller/knowledge/imports/preview', route => route.fulfill({ json: { proposals: ['Фрагмент'], document_id: 'new', sheets: [], document: { id: 'new', name: 'facts.txt', type: 'txt', status: 'previewed', has_source: true } } }));
 await page.goto(url + '&noeditor'); await page.getByLabel('Файл', { exact: true }).setInputFiles({ name: 'facts.txt', mimeType: 'text/plain', buffer: Buffer.from('text') });
 await page.getByRole('button', { name: 'Посмотреть предложения' }).click(); await expect(page.getByText('создайте черновик')).toBeVisible();
 await expect(page.getByRole('button', { name: 'Добавить выбранные в черновик' })).toHaveCount(0);
});
