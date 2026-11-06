import {expect,test} from '@playwright/test';
import {createServer,type ViteDevServer} from 'vite';
import {mkdir} from 'node:fs/promises';
let server:ViteDevServer;let url:string;
test.setTimeout(60000);
test.use({viewport:{width:1440,height:900},launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{}});
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E8-22D/vite.config.ts'});await server.listen();url=new URL('/tests/Browser/E8-22D/fixture.html',server.resolvedUrls!.local[0]).href;});test.afterAll(async()=>{await server?.close();});
test('подтверждённая рекомендация показана в действии',async({page})=>{await page.route('**/vision/actions/effect',route=>route.fulfill({json:{items:[],recommendations:{a:{priority:'Высокий',steps:['Уточнить условия','Обновить страницу'],question_ids:['q1','q2']}}}}));await page.goto(url);await expect(page.getByText('Высокий',{exact:true})).toBeVisible();await expect(page.getByText('Поможет ответить на 2 вопроса')).toBeVisible();await expect(page.getByText('Уточнить условия; Обновить страницу')).toBeVisible();await mkdir('.local/screenshots/SP-E8-22D',{recursive:true});await page.screenshot({path:'.local/screenshots/SP-E8-22D/recommendation.png',fullPage:true});});
test('без рекомендации сохранено пустое состояние',async({page})=>{await page.route('**/vision/actions/effect',route=>route.fulfill({json:{items:[],recommendations:{}}}));await page.goto(url);await expect(page.getByText('не указан',{exact:true})).toBeVisible();await expect(page.getByText('нет данных',{exact:true})).toBeVisible();});
