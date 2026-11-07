import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let vite:ViteDevServer;let url:string;
test.use({viewport:{width:1440,height:900},launchOptions:process.env.E817B_CHROMIUM_PATH?{executablePath:process.env.E817B_CHROMIUM_PATH}:{}});
test.beforeAll(async()=>{vite=await createServer({configFile:'tests/Browser/E8-17B/vite.config.ts',server:{host:'127.0.0.1',port:0,strictPort:false}});await vite.listen();url=new URL('/tests/Browser/E8-17B/fixture.html',vite.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await vite?.close();});
test('баннер уточнений в отчёте',async({page})=>{await page.goto(url);await expect(page.getByText('Уточнений ждут ответа: 2. Отчёт может быть неточным.')).toBeVisible();await expect(page.getByRole('link',{name:/Вернуться к уточнениям/})).toHaveAttribute('href','/vision/runs/m/clarify/mentions');await mkdir('.local/screenshots/sp-e8-17b',{recursive:true});await page.screenshot({path:'.local/screenshots/sp-e8-17b/report.png',fullPage:true});});
