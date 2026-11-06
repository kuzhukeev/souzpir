import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer;
let url: string;
test.use({ viewport: {width: 1440, height: 900}, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? {executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH} : {} });
test.beforeAll(async () => { server = await createServer({configFile:'tests/Browser/E8-16J/vite.config.ts',server:{host:'127.0.0.1',port:0,strictPort:false}}); await server.listen(); url = new URL('/tests/Browser/E8-16J/fixture.html',server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => {await server?.close();});
test('шапка и легенда показывают реальные данные матрицы', async ({page}) => {await page.goto(url);await expect(page.getByText('Замер № 97 · 2 модели · 2 прогона')).toBeVisible();await expect(page.getByLabel('Легенда матрицы').getByText('Упомянули вас')).toBeVisible();await expect(page.getByLabel('Легенда матрицы').getByText('Только конкуренты')).toBeVisible();await mkdir('.local/screenshots/SP-E8-16J',{recursive:true});await page.screenshot({path:'.local/screenshots/SP-E8-16J/matrix.png',fullPage:true});});
