import {expect,test} from '@playwright/test';
import {createServer,type ViteDevServer} from 'vite';
import {mkdir} from 'node:fs/promises';
let server:ViteDevServer;let url:string;
test.use({viewport:{width:1440,height:900},launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{}});
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E8-21G/vite.config.ts',server:{host:'127.0.0.1',port:0}});await server.listen();url=new URL('/tests/Browser/E8-21G/fixture.html',server.resolvedUrls!.local[0]).href;});test.afterAll(async()=>{await server?.close();});
test('группы соответствуют проверкам и показывают исходный вес',async({page})=>{await page.goto(url);const group=page.locator('details').filter({has:page.locator('summary').filter({hasText:'Текст и структурированные данные'})}).first();await expect(group.getByText('Структурированные данные',{exact:true})).toBeVisible();await group.getByText('Структурированные данные',{exact:true}).click();await expect(group.getByText('Вес в расчёте готовности: 5. Качественная важность не определена.')).toBeVisible();await expect(group.getByText('Источник: https://example.test/catalog')).toBeVisible();await mkdir('.local/screenshots/sp-e8-21g',{recursive:true});await page.screenshot({path:'.local/screenshots/sp-e8-21g/criteria.png',fullPage:true});});
