import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer; let url: string;
test.setTimeout(60000);
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { server = await createServer({ configFile: 'tests/Browser/E8-24I/vite.config.ts', server: { host: '127.0.0.1', port: 0, strictPort: false } }); await server.listen(); url = new URL('/tests/Browser/E8-24I/fixture.html', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });
test('персональная ссылка, журнал и отзыв', async ({ page, context }) => {
 await context.grantPermissions(['clipboard-read', 'clipboard-write']);
 await page.goto(url);
 await page.getByRole('button', { name: 'Получатели', exact: true }).click();
 await expect(page.getByLabel('Персональная ссылка')).toHaveValue('https://example.test/v/test');
 await page.getByRole('button', { name: 'Копировать', exact: true }).click();
 await expect(page.getByText('Скопировано', { exact: true })).toBeVisible();
 await page.getByText('Открытия: 1').click();
 await expect(page.getByText('Получатель · user@example.test')).toBeVisible();
 await mkdir('.local/screenshots/SP-E8-24I', { recursive: true });
 await page.screenshot({ path: '.local/screenshots/SP-E8-24I/personal.png', fullPage: true });
 await page.getByLabel('Имя получателя').fill('Анна');
 await page.getByLabel('Электронная почта').fill('anna@example.test');
 const post = page.waitForRequest(request => request.url().endsWith('/vision/settings/personal-report-access') && request.method() === 'POST');
 await page.route('**/vision/settings/personal-report-access', route => route.abort());
 await page.getByRole('button', { name: 'Создать ссылку' }).click();
 expect((await post).postDataJSON()).toMatchObject({ name: 'Анна', email: 'anna@example.test', report_id: '01ARZ3NDEKTSV4RRFFQ69G5FAV' });
 await expect(page.getByRole('alert')).toBeVisible();
 const revoke = page.waitForRequest(request => request.url().endsWith('/vision/settings/personal-report-access/01ARZ3NDEKTSV4RRFFQ69G5FAW') && request.method() === 'DELETE');
 await page.route('**/vision/settings/personal-report-access/*', route => route.abort());
 await page.getByRole('button', { name: 'Отозвать' }).click();
 expect((await revoke).method()).toBe('DELETE');
});


test('participant table exposes role and saves channel selections', async ({ page }) => {
 await page.goto(url);
 await page.getByRole('button', { name: 'Получатели', exact: true }).click();
 const table = page.getByRole('table', { name: 'Каналы получения отчёта участниками бренда' });
 await expect(table).toBeVisible();
 await expect(table).toContainText('Редактор');
 await expect(table).toContainText('Наблюдатель');
 await expect(table).toContainText('Менеджер');
 await expect(table).toContainText('По праву просмотра замеров');
 await page.screenshot({ path: '.local/screenshots/SP-E8-24I/table.png', fullPage: true });
 await page.getByLabel('Мария Иванова: email').check();
 const saved = page.waitForRequest(request => request.url().endsWith('/vision/settings/recipients') && request.method() === 'PUT');
 await page.route('**/vision/settings/recipients', route => route.abort());
 await page.getByRole('button', { name: 'Сохранить получателей' }).click();
 expect((await saved).postDataJSON().members).toContainEqual({ id: 'e', cabinet: true, email: true });
 await expect(page.getByRole('alert')).toBeVisible();
});

test('read-only access and layout across widths', async ({ page }) => {
 for (const width of [1440, 1024, 390]) {
  await page.setViewportSize({ width, height: 900 });
  await page.goto(url + '?readonly=1');
  await page.getByRole('button', { name: 'Получатели', exact: true }).click();
  await expect(page.getByLabel('Иван Петров: email')).toBeDisabled();
  await expect(page.getByRole('button', { name: 'Сохранить получателей' })).toHaveCount(0);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
 }
});
