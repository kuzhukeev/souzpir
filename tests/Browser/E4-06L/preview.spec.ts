import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer; let url: string;
test.setTimeout(60000);
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { server = await createServer({ configFile: 'tests/Browser/E4-06L/vite.config.ts', server: { host: '127.0.0.1', port: 0 } }); await server.listen(); url = new URL('/tests/Browser/E4-06L/fixture.html?imports', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });
test('сохранённый документ открывается без повторной загрузки', async ({ page }) => {
 await page.route('**/seller/knowledge/imports/documents/saved/preview?*', route => route.fulfill({ json: { proposals: ['Факт из файла'], document_id: 'saved', sheets: [], document: { id: 'saved', name: 'Ранее.pdf', type: 'pdf', pages: 3, status: 'imported', fragments_count: 7, has_source: true, created_at: '2026-11-01' } } }));
 await page.goto(url);
 await page.getByLabel('Действия: Ранее.pdf').click();
 await page.getByRole('button', { name: 'Посмотреть', exact: true }).click();
 await expect(page.getByRole('heading', { name: 'Сопоставление фактов' })).toBeVisible();
 await expect(page.getByText('Факт из файла', { exact: true })).toBeVisible();
 await page.getByRole('button', { name: 'К документам' }).click();
 await expect(page.getByRole('row').filter({ hasText: 'Старый.txt' })).toContainText('Исходник недоступен');
 await mkdir('.local/screenshots/SP-E4-06L', { recursive: true });
 await page.screenshot({ path: '.local/screenshots/SP-E4-06L/saved-preview.png', fullPage: true });
});

test('ошибка повторного просмотра показана пользователю', async ({ page }) => {
 await page.route('**/seller/knowledge/imports/documents/saved/preview?*', route => route.fulfill({ status: 404, json: { message: 'Not found' } }));
 await page.goto(url);
 await page.getByLabel('Действия: Ранее.pdf').click();
 await page.getByRole('button', { name: 'Посмотреть', exact: true }).click();
 await expect(page.getByRole('alert').filter({ hasText: 'Не удалось открыть сохранённый документ.' })).toBeVisible();
});


test('table states and keyboard menu stay usable on three widths', async ({ page }) => {
 for (const width of [1440,1024,390]) {
  await page.setViewportSize({width,height:900});
  await page.goto(url);
  await expect(page.getByRole('row').filter({hasText:'Ошибка.txt'})).toContainText('Ошибка');
  await expect(page.getByRole('row').filter({hasText:'Новый.docx'})).toContainText('Загружен');
  const toggle=page.getByLabel('Действия: Ранее.pdf');
  await toggle.focus(); await page.keyboard.press('Enter');
  await expect(page.getByRole('link',{name:'Скачать исходник'})).toBeVisible();
  await page.keyboard.press('Escape'); await expect(toggle).toBeFocused();
  expect(await page.evaluate(()=>document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
 }
});

test('empty documents and three themes', async ({page}) => {
 for (const theme of ['neutral','confectionery','pyro']) {
  await page.goto(url + '&empty=1&theme=' + theme);
  await expect(page.locator('html')).toHaveAttribute('data-theme',theme);
  await expect(page.getByText('Документов пока нет.')).toBeVisible();
  await expect(page.getByLabel('Файл', {exact:true})).toBeVisible();
 }
 await page.goto(url + '&empty=1');
 await page.screenshot({path:'.local/screenshots/SP-E4-06L/empty.png',fullPage:true});
});
