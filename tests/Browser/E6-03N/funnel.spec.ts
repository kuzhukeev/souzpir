import {test,expect} from '@playwright/test';
import {createServer,type ViteDevServer} from 'vite';
import {mkdir} from 'node:fs/promises';
let server:ViteDevServer;let url:string;
test.use({viewport:{width:1440,height:900},launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{}});
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E6-03N/vite.config.ts',server:{host:'127.0.0.1',port:0,strictPort:false}});await server.listen();url=new URL('/tests/Browser/E6-03N/fixture.html',server.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await server?.close();});
test('обязательные поля стадии и неподтверждённые сведения',async({page})=>{await page.goto(url);await page.getByRole('button',{name:'Квалификация'}).click();const stage=page.locator('div').filter({has:page.getByRole('heading',{name:'Стадия: Квалификация'})}).filter({has:page.locator('dl')}).last();await expect(stage.getByText('Город',{exact:true})).toBeVisible();await expect(stage.getByText('Необязательное')).toHaveCount(0);await expect(stage.getByText('Другая стадия')).toHaveCount(0);await expect(stage.getByText('Узнать: Город')).toBeVisible();await expect(stage.getByText('Не указана в опубликованной политике')).toHaveCount(0);await mkdir('.local/screenshots/SP-E6-03N',{recursive:true});await page.screenshot({path:'.local/screenshots/SP-E6-03N/funnel.png',fullPage:true});});
