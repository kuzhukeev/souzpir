import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';

let server: ViteDevServer;
let fixture: string;
test.setTimeout(60000);
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { server = await createServer({ configFile: 'tests/Browser/E8-21O/vite.config.ts', server: { host: '127.0.0.1', port: 0, strictPort: false } }); await server.listen(); fixture = new URL('/tests/Browser/E8-21O/fixture.html', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });
test('показывает измеренное время проверки',async({page})=>{await page.goto(fixture);await page.getByRole('button',{name:'Завершить проверку'}).click();await expect(page.getByText('Время проверки: 37 с.')).toBeVisible();await mkdir('.local/screenshots/SP-E8-21O',{recursive:true});await page.screenshot({path:'.local/screenshots/SP-E8-21O/completed.png',fullPage:true});});
