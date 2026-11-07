import {test,expect} from '@playwright/test';
import {createServer,type ViteDevServer} from 'vite';
import {mkdir} from 'node:fs/promises';
let server:ViteDevServer;let base:string;
test.use({viewport:{width:1440,height:900},launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{}});
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E6-07D/vite.config.ts',server:{host:'127.0.0.1',port:0,strictPort:false}});await server.listen();base=new URL('/tests/Browser/E6-07D/',server.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await server?.close();});

test('режим бота: пояснение про переопределение каналом',async({page})=>{
 await page.goto(base+'schedule.html');
 await expect(page.getByText('Каналы могут переопределять общий режим модуля.')).toBeVisible();
 await mkdir('.local/screenshots/SP-E6-07D',{recursive:true});
 await page.screenshot({path:'.local/screenshots/SP-E6-07D/schedule.png',fullPage:true});
});

test('лимиты: запасная фраза при исчерпании',async({page})=>{
 await page.goto(base+'limits.html');
 await expect(page.getByText(/автоматически передаётся менеджеру/)).toBeVisible();
 await expect(page.getByText('Передаю менеджеру, он напишет сегодня до конца дня.')).toBeVisible();
 await page.screenshot({path:'.local/screenshots/SP-E6-07D/limits.png',fullPage:true});
});
