import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer; let url: string;
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { server = await createServer({ configFile: 'tests/Browser/E8-24K/vite.config.ts', server: { host: '127.0.0.1', port: 0, strictPort: false } }); await server.listen(); url = new URL('/tests/Browser/E8-24K/fixture.html', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });
test('получатели видят фактический статус доступа к ответам', async ({page}) => {
 await page.goto(url);
 await page.getByRole('button', {name:'Получатели', exact:true}).click();
 const table = page.getByRole('table', {name:'Каналы получения отчёта участниками бренда'});
 const rows = table.getByRole('row');
 await expect(rows.nth(1)).toContainText('Есть доступ');
 await expect(rows.nth(3)).toContainText('Есть доступ');
 await expect(rows.nth(4)).toContainText('Нет доступа');
 await mkdir('.local/screenshots/SP-E8-24K', {recursive:true});
 await page.screenshot({path:'.local/screenshots/SP-E8-24K/permissions.png',fullPage:true});
});


test('empty recipients and reports in each theme', async ({page}) => {
 for (const theme of ['neutral','confectionery','pyro']) {
  await page.goto(url + '?empty=1&theme=' + theme);
  await page.getByRole('button',{name:'Получатели',exact:true}).click();
  await expect(page.locator('html')).toHaveAttribute('data-theme',theme);
  await expect(page.getByText('Получатели пока не выбраны.')).toBeVisible();
  await expect(page.getByText('Нет отчётов, доступных для публикации.')).toBeVisible();
  await expect(page.getByText('Ссылок пока нет.')).toBeVisible();
  await page.getByRole('button',{name:'Выбрать участников'}).click();
  await expect(page.getByLabel('Иван Петров: email')).toBeVisible();
 }
 await page.goto(url + '?empty=1');
 await page.getByRole('button',{name:'Получатели',exact:true}).click();
 await page.screenshot({path:'.local/screenshots/SP-E8-24K/empty.png',fullPage:true});
});

test('external access error and read-only state', async ({page}) => {
 await page.goto(url);
 await page.getByRole('button',{name:'Получатели',exact:true}).click();
 await expect(page.getByText('Внешние получатели видят только публичную версию.',{exact:false})).toBeVisible();
 await page.getByLabel('Имя получателя').fill('Анна');
 await page.getByLabel('Электронная почта').fill('anna@example.test');
 await page.route('**/vision/settings/personal-report-access',route=>route.abort());
 await page.getByRole('button',{name:'Создать ссылку'}).click();
 await expect(page.getByRole('alert')).toContainText('Не удалось выполнить действие.');
 await page.screenshot({path:'.local/screenshots/SP-E8-24K/error.png',fullPage:true});
 for (const width of [1440,1024,390]) {
  await page.setViewportSize({width,height:900});
  await page.goto(url + '?readonly=1');
  await page.getByRole('button',{name:'Получатели',exact:true}).click();
  await expect(page.getByLabel('Иван Петров: email')).toBeDisabled();
  await expect(page.getByRole('heading',{name:'Персональный доступ к отчёту'})).toHaveCount(0);
  expect(await page.evaluate(()=>document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true);
 }
});
