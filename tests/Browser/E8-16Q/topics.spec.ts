import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer; let url: string;
test.use({viewport: {width:1440,height:900}, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? {executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH} : {}});
test.beforeAll(async () => {server=await createServer({configFile:'tests/Browser/E8-16Q/vite.config.ts', server:{host:'127.0.0.1',port:0,strictPort:false}}); await server.listen(); url=new URL('/tests/Browser/E8-16Q/fixture.html?page=report',server.resolvedUrls!.local[0]).href;});
test.afterAll(async () => {await server?.close();});
test('показывает подтверждённые темы без выдуманной динамики', async ({page}) => {
 await page.goto(url);
 const topics=page.locator('#topics');
 await expect(topics.getByRole('row',{name:/Цена/})).toContainText('75');
 await expect(topics.getByRole('row',{name:/Выбор/})).toContainText('25');
 await expect(topics.getByText('Нет данных')).toHaveCount(1);
 await expect(topics.getByText('+5 п.п.')).toBeVisible();
 await mkdir('.local/screenshots/SP-E8-16Q',{recursive:true});
 await page.screenshot({path:'.local/screenshots/SP-E8-16Q/report.png',fullPage:true});
 await page.goto(url+'&emptytopics=1');
 await expect(page.getByText('Нет подтверждённых данных по темам.')).toBeVisible();
});
