import {expect,test} from '@playwright/test';
import {createServer,type ViteDevServer} from 'vite';
import {mkdir} from 'node:fs/promises';
let server:ViteDevServer;let url:string;
test.setTimeout(60000);test.use({viewport:{width:1440,height:900},launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{}});
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Feature/Seller/Channels/Email/vite-e507b.config.ts'});await server.listen();url=new URL('/tests/Feature/Seller/Channels/Email/preview-e507b.html',server.resolvedUrls!.local[0]).href;});test.afterAll(async()=>{await server?.close();});
test('настройки отправителя прайса',async({page})=>{await page.goto(url);await expect(page.getByRole('heading',{name:'Почта для прайса'})).toBeVisible();await expect(page.getByLabel('Адрес отправителя')).toHaveValue('sender@example.test');await expect(page.getByText('Автоматическая отправка ещё подключается.',{exact:false})).toBeVisible();await mkdir('.local/screenshots/SP-E5-07B',{recursive:true});await page.screenshot({path:'.local/screenshots/SP-E5-07B/email.png',fullPage:true});});
