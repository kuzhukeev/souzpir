import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer; let url: string;
test.setTimeout(60000);
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { server = await createServer({ configFile: 'tests/Browser/E4-06H/vite.config.ts' }); await server.listen(); url = new URL('/tests/Browser/E4-06H/fixture.html?imports', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });
test('сохранённый документ открывается без повторной загрузки', async ({ page }) => {
 await page.route('**/seller/knowledge/imports/documents/saved/preview?*', route => route.fulfill({ json: { proposals: ['Факт из файла'], document_id: 'saved', sheets: [], document: { id: 'saved', name: 'Ранее.pdf', type: 'pdf', pages: 3, status: 'imported', fragments_count: 7, has_source: true, created_at: '2026-11-01' } } }));
 await page.goto(url);
 await page.getByLabel('Действия: Ранее.pdf').click();
 await page.getByRole('button', { name: 'Посмотреть', exact: true }).click();
 await expect(page.getByRole('heading', { name: 'Сопоставление фактов' })).toBeVisible();
 await expect(page.getByText('Факт из файла', { exact: true })).toBeVisible();
 await expect(page.getByRole('row').filter({ hasText: 'Старый.txt' })).toContainText('Исходник недоступен');
 await mkdir('.local/screenshots/SP-E4-06H', { recursive: true });
 await page.screenshot({ path: '.local/screenshots/SP-E4-06H/saved-preview.png', fullPage: true });
});

test('ошибка повторного просмотра показана пользователю', async ({ page }) => {
 await page.route('**/seller/knowledge/imports/documents/saved/preview?*', route => route.fulfill({ status: 404, json: { message: 'Not found' } }));
 await page.goto(url);
 await page.getByLabel('Действия: Ранее.pdf').click();
 await page.getByRole('button', { name: 'Посмотреть', exact: true }).click();
 await expect(page.getByRole('alert').filter({ hasText: 'Не удалось открыть сохранённый документ.' })).toBeVisible();
});
