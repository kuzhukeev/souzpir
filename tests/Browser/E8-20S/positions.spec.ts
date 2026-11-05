import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer; let url: string;
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { server = await createServer({configFile:'tests/Browser/E8-20S/vite.config.ts',cacheDir:'.local/runtime/vite-e820s',server:{host:'127.0.0.1',port:0,strictPort:false}}); await server.listen(); url = new URL('/tests/Browser/E8-20S/fixture.html',server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });
test('shows confirmed position and missing data', async ({page}) => {
 await page.goto(url);
 await expect(page.getByText('Средняя позиция:')).toContainText('2,5');
 await expect(page.getByText('Подтверждений: 2')).toBeVisible();
 await mkdir('.local/screenshots/SP-E8-20S',{recursive:true});
 await page.screenshot({path:'.local/screenshots/SP-E8-20S/company.png',fullPage:true});
 await page.goto(url+'?missing');
 await expect(page.getByText('Средняя позиция:')).toContainText('Нет данных');
 await expect(page.getByText('Подтверждений:')).toHaveCount(0);
});
