import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer; let url: string;
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { server = await createServer({ configFile: 'tests/Browser/E8-24J/vite.config.ts', server: { host: '127.0.0.1', port: 0, strictPort: false } }); await server.listen(); url = new URL('/tests/Browser/E8-24J/fixture.html', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });
test('получатели видят фактический статус доступа к ответам', async ({page}) => {
 await page.goto(url);
 await page.getByRole('button', {name:'Получатели', exact:true}).click();
 const table = page.getByRole('table', {name:'Каналы получения отчёта участниками бренда'});
 const rows = table.getByRole('row');
 await expect(rows.nth(1)).toContainText('Есть доступ');
 await expect(rows.nth(3)).toContainText('Есть доступ');
 await expect(rows.nth(4)).toContainText('Нет доступа');
 await mkdir('.local/screenshots/SP-E8-24J', {recursive:true});
 await page.screenshot({path:'.local/screenshots/SP-E8-24J/permissions.png',fullPage:true});
});
