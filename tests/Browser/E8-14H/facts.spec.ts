import {test,expect} from '@playwright/test';
import {createServer,type ViteDevServer} from 'vite';
import {mkdir} from 'node:fs/promises';
let server:ViteDevServer;let url:string;
test.use({viewport:{width:1440,height:900},launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{}});
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E8-14H/vite.config.ts',cacheDir:'.local/runtime/vite-e814h',server:{host:'127.0.0.1',port:0,strictPort:false}});await server.listen();url=new URL('/tests/Browser/E8-14H/fixture.html',server.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await server?.close();});
test('подтверждённое свидетельство доставки можно добавить в факты',async({page})=>{await page.goto(`${url}?screen=facts`);await page.getByRole('button',{name:'Добавить факт: Доставка в течение двух дней'}).click();await expect(page.getByText('Доставка в течение двух дней',{exact:true})).toBeVisible();await mkdir('.local/screenshots/SP-E8-14H',{recursive:true});await page.screenshot({path:'.local/screenshots/SP-E8-14H/fact.png',fullPage:true});});
