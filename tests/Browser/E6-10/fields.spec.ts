import {expect,test} from '@playwright/test';
import {createServer,type ViteDevServer} from 'vite';
import {mkdir} from 'node:fs/promises';
let server:ViteDevServer;let url:string;
test.use({viewport:{width:1440,height:900},launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{}});
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E6-10/vite.config.ts',cacheDir:'.local/runtime/vite-e610',server:{host:'127.0.0.1',port:0,strictPort:false}});await server.listen();url=new URL('/tests/Browser/E6-10/fixture.html',server.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await server?.close();});
test('редактор полей черновика показывает тип, варианты и этап',async({page})=>{await mkdir('.local/screenshots/SP-E6-10',{recursive:true});await page.goto(url);await expect(page.getByRole('heading',{name:'Поля бренда в черновике'})).toBeVisible();await expect(page.getByLabel('Название',{exact:true})).toHaveValue('Формат точки');await expect(page.getByLabel('Варианты, по одному на строку')).toHaveValue('Павильон\nОнлайн');await page.screenshot({path:'.local/screenshots/SP-E6-10/fields.png',fullPage:true});await page.getByRole('button',{name:'Добавить поле'}).click();await expect(page.getByRole('group',{name:'Поле 2'})).toBeVisible();});
