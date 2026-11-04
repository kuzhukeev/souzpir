import {test,expect} from '@playwright/test';
import {createServer,type ViteDevServer} from 'vite';
import {mkdir} from 'node:fs/promises';
let server:ViteDevServer;let url:string;
test.use({viewport:{width:1440,height:900},launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{}});
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E8-20E/vite.config.ts',server:{host:'127.0.0.1',port:0}});await server.listen();url=new URL('/tests/Browser/E8-20E/fixture.html',server.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await server?.close();});
test('радар требует совпадения ответов',async({page})=>{await page.goto(url);await page.getByLabel('Первая компания').selectOption('Альфа');await page.getByLabel('Вторая компания').selectOption('Бета');await expect(page.getByRole('img',{name:'Радар долей упоминаний по намерениям'})).toBeVisible();await mkdir('.local/screenshots/sp-e8-20e',{recursive:true});await page.screenshot({path:'.local/screenshots/sp-e8-20e/compare.png',fullPage:true});await page.goto(`${url}?incomplete=1`);await page.getByLabel('Первая компания').selectOption('Альфа');await page.getByLabel('Вторая компания').selectOption('Бета');await expect(page.getByText('Для радара нужны рассчитанные доли',{exact:false})).toBeVisible();await expect(page.getByRole('img',{name:'Радар долей упоминаний по намерениям'})).toHaveCount(0);});
