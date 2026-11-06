import {expect,test} from '@playwright/test';
import {createServer,type ViteDevServer} from 'vite';
import {mkdir} from 'node:fs/promises';
let server:ViteDevServer;let url:string;
test.setTimeout(60000);
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E7-01L/vite.config.ts',server:{host:'127.0.0.1',port:0}});await server.listen();url=new URL('/tests/Browser/E7-01L/fixture.html',server.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await server?.close();});
test.use({viewport:{width:1440,height:900},launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{}});
test('кнопка запускает обозначенную тестовую оценку',async({page})=>{
 await page.goto(url);await page.getByRole('button',{name:'Оценка диалога'}).click();
 await expect(page.getByRole('dialog')).toBeVisible();await expect(page.getByText(/не отражает качество диалога/)).toBeVisible();
 const button=page.getByRole('button',{name:'Оценить сейчас'});await expect(button).toBeEnabled();
 await mkdir('.local/screenshots/SP-E7-01L',{recursive:true});await page.screenshot({path:'.local/screenshots/SP-E7-01L/evaluation.png'});
 let posted=false;await page.route('**/seller/quality/dialogs/*/evaluate',async route=>{posted=route.request().method()==='POST';await route.fulfill({status:204});});
 await button.click();await expect.poll(()=>posted).toBe(true);
});
