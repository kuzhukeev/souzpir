import {expect,test} from '@playwright/test';
import {createServer,type ViteDevServer} from 'vite';
import {mkdir} from 'node:fs/promises';
let server:ViteDevServer;let url:string;
test.setTimeout(60000);test.use({viewport:{width:1440,height:900},launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{}});
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E8-14C/vite.config.ts',server:{host:'127.0.0.1',port:0}});await server.listen();url=new URL('/tests/Browser/E8-14C/fixture.html',server.resolvedUrls!.local[0]).href;});test.afterAll(async()=>{await server?.close();});
test('тариф и сохранённые лимиты',async({page})=>{await page.goto(url);await expect(page.getByText('Тестовый план')).toBeVisible();await expect(page.getByText('Вопросы:')).toBeVisible();await expect(page.getByText('30',{exact:true})).toBeVisible();await mkdir('.local/screenshots/SP-E8-14C',{recursive:true});await page.screenshot({path:'.local/screenshots/SP-E8-14C/start.png',fullPage:true});});
test('без данных показано пустое состояние',async({page})=>{await page.goto(url+'?missing=1');await expect(page.getByText('Данные о тарифе и лимитах пока недоступны.')).toBeVisible();});
