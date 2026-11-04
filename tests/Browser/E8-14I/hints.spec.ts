import {test,expect} from '@playwright/test';
import {createServer,type ViteDevServer} from 'vite';
import {mkdir} from 'node:fs/promises';
let server:ViteDevServer;let url:string;
test.use({viewport:{width:1440,height:900},launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{}});
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E8-14I/vite.config.ts',cacheDir:'.local/runtime/vite-e814i',server:{host:'127.0.0.1',port:0,strictPort:false}});await server.listen();url=new URL('/tests/Browser/E8-14I/fixture.html',server.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await server?.close();});
test('подсказка типа покупателя добавляется вручную',async({page})=>{await page.goto(url+'?screen=hints');const button=page.getByRole('button',{name:/Тип покупателя: Организаторы/});await expect(button).toBeVisible();await button.click();await expect(button).toBeDisabled();await mkdir('.local/screenshots/SP-E8-14I',{recursive:true});await page.screenshot({path:'.local/screenshots/SP-E8-14I/audience.png',fullPage:true});});