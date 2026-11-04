import {expect,test} from '@playwright/test';
import {createServer,type ViteDevServer} from 'vite';
import {mkdir} from 'node:fs/promises';
let server:ViteDevServer; let url:string;
test.use({viewport:{width:1440,height:900},launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{}});
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E8-14F/vite.config.ts',cacheDir:'.local/runtime/vite-e814f',server:{host:'127.0.0.1',port:0,strictPort:false}});await server.listen();url=new URL('/tests/Browser/E8-14F/fixture.html',server.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await server?.close();});
test('показывает сохранённый результат проверки в анкете',async({page})=>{await page.goto(`${url}?screen=quiz`);await expect(page.getByText('Проверка завершена.')).toBeVisible();await expect(page.getByText('Готовность к ИИ-поиску: 50–80 из 100 · Проверено страниц: 3')).toBeVisible();await mkdir('.local/screenshots/SP-E8-14F',{recursive:true});await page.screenshot({path:'.local/screenshots/SP-E8-14F/site-check.png',fullPage:true});});
