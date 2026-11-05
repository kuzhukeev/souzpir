import {expect,test} from '@playwright/test';
import {createServer,type ViteDevServer} from 'vite';
import {mkdir} from 'node:fs/promises';
let server:ViteDevServer;let url:string;
test.use({viewport:{width:1440,height:900},launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{}});
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E5-09/vite.config.ts',cacheDir:'.local/runtime/vite-e509',server:{host:'127.0.0.1',port:0,strictPort:false}});await server.listen();url=new URL('/tests/Browser/E5-09/fixture.html',server.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await server?.close();});
test('закрытие диалога и выполнение задачи доступны в карточке',async({page})=>{await mkdir('.local/screenshots/SP-E5-09',{recursive:true});await page.goto(url);await expect(page.getByRole('button',{name:'Закрыть диалог'})).toBeVisible();await expect(page.getByText('Отправить прайс')).toBeVisible();await expect(page.getByRole('button',{name:'Выполнено'})).toBeVisible();await page.screenshot({path:'.local/screenshots/SP-E5-09/dialog.png',fullPage:true});});
