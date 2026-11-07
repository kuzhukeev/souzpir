import {test,expect} from '@playwright/test';
import {createServer,type ViteDevServer} from 'vite';
import {mkdir} from 'node:fs/promises';
let server:ViteDevServer;let url:string;
test.use({viewport:{width:1440,height:900},launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{}});
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E6-06D/vite.config.ts',server:{host:'127.0.0.1',port:0,strictPort:false}});await server.listen();url=new URL('/tests/Browser/E6-06D/fixture.html',server.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await server?.close();});
test('режим и распределение',async({page})=>{await page.goto(url);await expect(page.getByText('Ответим утром')).toBeVisible();await expect(page.getByRole('link',{name:'Изменить служебную фразу →'})).toHaveAttribute('href','/seller/persona');await mkdir('.local/screenshots/SP-E6-06D',{recursive:true});await page.screenshot({path:'.local/screenshots/SP-E6-06D/schedule.png',fullPage:true});await page.getByRole('button',{name:'Распределение',exact:true}).click();await expect(page.getByRole('heading',{name:'Менеджеры'})).toBeVisible();await expect(page.getByText('Если никто не взял диалог')).toBeVisible();await expect(page.getByRole('radio',{name:/По городу клиента/}).first()).toBeEnabled();await page.screenshot({path:'.local/screenshots/SP-E6-06D/assignment.png',fullPage:true});});
