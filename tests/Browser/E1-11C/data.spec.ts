import {expect,test} from '@playwright/test';
import {createServer,type ViteDevServer} from 'vite';
import {mkdir} from 'node:fs/promises';
let server:ViteDevServer;let url:string;
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E1-11C/vite.config.ts',cacheDir:'.local/runtime/vite-e111c',server:{host:'127.0.0.1',port:0,strictPort:false}});await server.listen();url=new URL('/tests/Browser/E1-11C/fixture.html',server.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await server?.close();});
test.use({viewport:{width:1440,height:900},launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{}});
test('хранение и удаление показывают доступное состояние',async({page})=>{await page.goto(url);await expect(page.getByRole('heading',{name:'Хранение данных'})).toBeVisible();await expect(page.getByText('Срок хранения данных и автоматическая очистка пока не подтверждены.',{exact:false})).toBeVisible();await expect(page.getByText('Самостоятельное удаление аккаунта пока недоступно.',{exact:false})).toBeVisible();await expect(page.getByRole('link',{name:'Открыть поддержку'})).toHaveAttribute('href','/help/support');await mkdir('.local/screenshots/SP-E1-11C',{recursive:true});await page.screenshot({path:'.local/screenshots/SP-E1-11C/data.png',fullPage:true});});
