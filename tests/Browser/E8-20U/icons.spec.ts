import {test,expect} from '@playwright/test'; import {createServer,type ViteDevServer} from 'vite'; import {mkdir} from 'node:fs/promises';
let server:ViteDevServer; let url:string;
test.use({viewport:{width:1440,height:900},launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{}});
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E8-20U/vite.config.ts',cacheDir:'.local/runtime/vite-e820u',server:{host:'127.0.0.1',port:0,strictPort:false}});await server.listen();url=new URL('/tests/Browser/E8-20U/fixture.html',server.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await server?.close();});
test('source table has a local generic icon',async({page})=>{await page.goto(url);await expect(page.getByTestId('source-icon').first()).toBeVisible();await expect(page.getByRole('columnheader',{name:'Источник'})).toBeVisible();await mkdir('.local/screenshots/SP-E8-20U',{recursive:true});await page.screenshot({path:'.local/screenshots/SP-E8-20U/sources.png',fullPage:true});});
