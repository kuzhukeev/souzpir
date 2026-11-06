import {expect,test} from '@playwright/test';
import {createServer,type ViteDevServer} from 'vite';
import {mkdir} from 'node:fs/promises';
let server:ViteDevServer;let url:string;
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E5-01H/vite.config.ts',cacheDir:'.local/runtime/vite-e501h',server:{host:'127.0.0.1',port:0}});await server.listen();url=new URL('/tests/Browser/E5-01H/fixture.html',server.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await server.close();});
test.use({launchOptions:{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}});
test('виден тип проверки токена',async({page})=>{await page.setViewportSize({width:1440,height:900});await page.goto(url+'?settings');await expect(page.getByText('Тестовая проверка:',{exact:false})).toBeVisible();await page.goto(url+'?settings&live');await expect(page.getByText('Бот подтверждён:',{exact:false})).toBeVisible();await mkdir('.local/screenshots/SP-E5-01H',{recursive:true});await page.screenshot({path:'.local/screenshots/SP-E5-01H/settings.png',fullPage:true});});
