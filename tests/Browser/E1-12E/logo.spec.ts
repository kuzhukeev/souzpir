import {expect,test} from '@playwright/test';
import {createServer,type ViteDevServer} from 'vite';
import {mkdir} from 'node:fs/promises';
let server:ViteDevServer;let url:string;
test.use({viewport:{width:1440,height:900},launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{}});
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E1-12E/vite.config.ts',cacheDir:'.local/runtime/vite-e112e',server:{host:'127.0.0.1',port:0,strictPort:false}});await server.listen();url=new URL('/tests/Browser/E1-12E/ui-fixture.html',server.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await server?.close();});
test('форма логотипа показывает разрешённые форматы и размер',async({page})=>{await page.goto(url);await page.getByRole('tab',{name:'Оформление кабинета'}).click();await expect(page.getByText('PNG или SVG, до 1 МБ')).toBeVisible();await expect(page.getByLabel('Загрузить логотип')).toHaveAttribute('accept','image/png,image/svg+xml');await mkdir('.local/screenshots/SP-E1-12E',{recursive:true});await page.screenshot({path:'.local/screenshots/SP-E1-12E/logo.png',fullPage:true});});
