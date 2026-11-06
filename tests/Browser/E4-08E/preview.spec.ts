import {expect,test} from '@playwright/test'; import {createServer,type ViteDevServer} from 'vite'; import {mkdir} from 'node:fs/promises';
let server:ViteDevServer; let url:string;
test.use({viewport:{width:1440,height:900},launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{}});
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E4-08E/vite.config.ts',cacheDir:'.local/runtime/vite-e408e',server:{host:'127.0.0.1',port:0,strictPort:false}});await server.listen();url=new URL('/tests/Browser/E4-08E/fixture.html',server.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await server?.close();});
test('fake response is visible in scenario',async({page})=>{await page.goto(url);await expect(page.getByText('Продавец: Ответ из fake runtime')).toBeVisible();await mkdir('.local/screenshots/SP-E4-08E',{recursive:true});await page.screenshot({path:'.local/screenshots/SP-E4-08E/run.png',fullPage:true});});
