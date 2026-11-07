import {expect,test} from '@playwright/test'; import {createServer,type ViteDevServer} from 'vite'; import {mkdir} from 'node:fs/promises';
let server:ViteDevServer;let url:string;
test.use({viewport:{width:1440,height:900},launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{}});
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E5-01J/vite.config.ts',cacheDir:'.local/runtime/vite-e501j',server:{host:'127.0.0.1',port:0,strictPort:false}});await server.listen();url=new URL('/tests/Browser/E5-01J/fixture.html',server.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await server?.close();});
test('widget channel explains manager handoff',async({page})=>{await page.goto(url);await expect(page.getByText('Новые обращения виджета поступают в диалоги для менеджера.')).toBeVisible();await mkdir('.local/screenshots/SP-E5-01J',{recursive:true});await page.screenshot({path:'.local/screenshots/SP-E5-01J/channels.png',fullPage:true});});
