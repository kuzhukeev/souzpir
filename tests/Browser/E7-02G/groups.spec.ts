import {test,expect} from '@playwright/test';
import {createServer,type ViteDevServer} from 'vite';
import {mkdir} from 'node:fs/promises';
let server:ViteDevServer;let url:string;
test.use({viewport:{width:1440,height:900},launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{}});
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E7-02G/vite.config.ts',cacheDir:'.local/runtime/vite-e702g',server:{host:'127.0.0.1',port:0,strictPort:false}});await server.listen();url=new URL('/tests/Browser/E7-02G/fixture.html',server.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await server?.close();});
test('групповой прогон различает полученную и недоступную оценку',async({page})=>{await page.goto(url);await expect(page.getByText('Оценка временно недоступна')).toBeVisible();await expect(page.getByText('4.2 / 5',{exact:false}).first()).toBeVisible();await mkdir('.local/screenshots/SP-E7-02G',{recursive:true});await page.screenshot({path:'.local/screenshots/SP-E7-02G/groups.png',fullPage:true});});
