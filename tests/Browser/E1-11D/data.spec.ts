import {expect,test} from '@playwright/test';
import {createServer,type ViteDevServer} from 'vite';
import {mkdir} from 'node:fs/promises';
let server:ViteDevServer;let url:string;
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E1-11D/vite.config.ts',server:{host:'127.0.0.1',port:0}});await server.listen();url=new URL('/tests/Browser/E1-11D/fixture.html',server.resolvedUrls!.local[0]).href;});test.afterAll(async()=>{await server?.close();});
test.use({viewport:{width:1440,height:900},launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{}});
test('подтверждённые сроки и обращение в поддержку',async({page})=>{await page.goto(url);await expect(page.getByText('аудит — 365 дней, экспорты — 30 дней.',{exact:false})).toBeVisible();await expect(page.getByText('Новая автоматическая очистка не вводится.',{exact:false})).toBeVisible();await expect(page.getByText('Аккаунт владельца и связанная история сохраняются',{exact:false})).toBeVisible();await expect(page.getByRole('link',{name:'Открыть поддержку'})).toHaveAttribute('href','/help/support');await mkdir('.local/screenshots/SP-E1-11D',{recursive:true});await page.screenshot({path:'.local/screenshots/SP-E1-11D/data.png',fullPage:true});});
