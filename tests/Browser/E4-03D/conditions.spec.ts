import {expect,test} from '@playwright/test';
import {createServer,type ViteDevServer} from 'vite';
import {mkdir} from 'node:fs/promises';
let server:ViteDevServer;let url:string;
test.use({viewport:{width:1440,height:900},launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{}});
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E4-03D/vite.config.ts',cacheDir:'.local/runtime/vite-e403d',server:{host:'127.0.0.1',port:0,strictPort:false}});await server.listen();url=new URL('/tests/Browser/E4-03D/fixture.html',server.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await server?.close();});
test('акции и подтверждение сохранения',async({page})=>{await mkdir('.local/screenshots/SP-E4-03D',{recursive:true});await page.goto(url);await expect(page.getByRole('heading',{name:'Акции'})).toBeVisible();await expect(page.getByLabel('Название',{exact:true})).toHaveValue('Осенняя акция');await expect(page.getByText('Утверждено',{exact:true})).toHaveCount(0);await page.screenshot({path:'.local/screenshots/SP-E4-03D/conditions.png',fullPage:true});await page.getByRole('button',{name:'Сохранить условия'}).click();await expect(page.getByText('Изменения вступят в силу после публикации версии.')).toBeVisible();await page.screenshot({path:'.local/screenshots/SP-E4-03D/confirm.png'});});
