import {test,expect} from '@playwright/test';
import {createServer,type ViteDevServer} from 'vite';
import {mkdir} from 'node:fs/promises';
let server:ViteDevServer; let url:string;
test.use({viewport:{width:1440,height:900},launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{}});
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E5-04K/vite.config.ts',cacheDir:'.local/runtime/vite-e504k',server:{host:'127.0.0.1',port:0,strictPort:false}});await server.listen();url=new URL('/tests/Browser/E5-04K/fixture.html',server.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await server?.close();});
test('показывает клиента, канал и текст готовой подсказки',async({page})=>{await page.goto(url);await expect(page.getByText('Анна Петрова')).toBeVisible();await expect(page.getByText('Telegram', {exact:false})).toBeVisible();await expect(page.getByText('Здравствуйте! Как оформить заказ?')).toBeVisible();await expect(page.getByText('Здравствуйте! Помогу вам оформить заказ.')).toBeVisible();await expect(page.getByText('Отправка и отклонение ответов пока недоступны.')).toBeVisible();await mkdir('.local/screenshots/sp-e5-04k',{recursive:true});await page.screenshot({path:'.local/screenshots/sp-e5-04k/queue.png',fullPage:true});});
