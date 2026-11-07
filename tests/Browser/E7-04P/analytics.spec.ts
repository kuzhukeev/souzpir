import {test,expect} from '@playwright/test';
import {createServer,type ViteDevServer} from 'vite';
let server:ViteDevServer; let url:string;
test.setTimeout(60000);
test.use({viewport:{width:1440,height:900},launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{}});
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E7-04/vite.config.ts',server:{host:'127.0.0.1',port:0}});await server.listen();url=new URL('/tests/Browser/E7-04P/details.html',server.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await server?.close();});
test('среднее время до контакта и диалоги по дням на вкладке Диалоги (D-8.2)',async({page})=>{
 await page.goto(url);
 await page.getByRole('button',{name:'Диалоги',exact:true}).click();
 await expect(page.getByRole('heading',{name:'Среднее время до контакта'})).toBeVisible();
 await expect(page.getByText('6 мин 20 сек',{exact:true})).toBeVisible();
 const dialogsSection = page.getByRole('region',{name:'Диалоги по дням'});
 await expect(dialogsSection.getByRole('heading',{name:'Диалоги по дням'})).toBeVisible();
 await expect(dialogsSection.getByText('2026-11-02')).toBeVisible();
 await page.screenshot({path:'.local/screenshots/SP-E7-04P/dialogs.png'});
});
test('топ тем вопросов столбиками с долей «не знаю» по теме (D-8.3)',async({page})=>{
 await page.goto(url);
 await page.getByRole('button',{name:'Темы и вопросы',exact:true}).click();
 const topics = page.locator('#topics');
 await expect(topics.getByRole('heading',{name:'Топ тем вопросов'})).toBeVisible();
 await expect(topics.getByText('Доставка',{exact:true})).toBeVisible();
 await expect(topics.getByText('50%',{exact:true})).toBeVisible();
 await page.screenshot({path:'.local/screenshots/SP-E7-04P/topics.png'});
});
