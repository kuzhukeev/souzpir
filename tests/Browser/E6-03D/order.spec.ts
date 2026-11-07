import {test,expect} from '@playwright/test';
import {createServer,type ViteDevServer} from 'vite';
import {mkdir} from 'node:fs/promises';
let server:ViteDevServer;let url:string;
test.use({viewport:{width:1440,height:900},launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{}});
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E6-03D/vite.config.ts',cacheDir:'.local/runtime/vite-e603d',server:{host:'127.0.0.1',port:0,strictPort:false}});await server.listen();url=new URL('/tests/Browser/E6-03D/fixture.html',server.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await server?.close();});
test('порядок вопросов меняется кнопками и отправляется в черновик',async({page})=>{await page.goto(url);const section=page.getByRole('heading',{name:'Не определён'}).locator('..');await section.getByRole('button',{name:'Поднять Цель'}).click();await expect(section.locator('li').first()).toContainText('Цель');await mkdir('.local/screenshots/SP-E6-03D',{recursive:true});await page.screenshot({path:'.local/screenshots/SP-E6-03D/order.png',fullPage:true});let payload:unknown;await page.route('**/seller/settings/slot-order',async route=>{payload=route.request().postDataJSON();await route.abort();});await page.getByRole('button',{name:'Сохранить порядок'}).click();await expect.poll(()=>payload).toEqual({version:1,slot_order:{unknown:['purpose','city','experience'],newbie:['city','purpose']}});});
test('без права редактирования форма скрыта',async({page})=>{await page.goto(url+'?readonly');await expect(page.getByRole('button',{name:'Сохранить порядок'})).toHaveCount(0);});
