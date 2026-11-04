import { test, expect } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer;
let url: string;
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { server = await createServer({configFile:'tests/Browser/E7-02F/vite.config.ts',cacheDir:'.local/runtime/vite-e702f',server:{host:'127.0.0.1',port:0,strictPort:false}}); await server.listen(); url = new URL('/tests/Browser/E7-02F/fixture.html', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });
test('group progress, summary and detail', async ({page}) => {
  await page.goto(url);
  await expect(page.getByText('Выполняется', {exact:false})).toBeVisible();
  await expect(page.getByText('Второй: Ожидает', {exact:false})).toBeVisible();
  await expect(page.getByText('Средняя оценка: 4.2 / 5', {exact:false})).toBeVisible();
  await mkdir('.local/screenshots/SP-E7-02F',{recursive:true});
  await page.screenshot({path:'.local/screenshots/SP-E7-02F/groups.png',fullPage:true});
  await page.getByRole('button',{name:'Открыть'}).first().click();
  await expect(page.getByRole('heading',{name:'Результат: Новый покупатель'})).toBeVisible();
});
