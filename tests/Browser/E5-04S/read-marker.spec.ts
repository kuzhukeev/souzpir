import {test,expect} from '@playwright/test';
import {createServer,type ViteDevServer} from 'vite';
import {mkdir} from 'node:fs/promises';
let server:ViteDevServer;let url:string;
test.use({viewport:{width:1440,height:900},launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{}});
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E5-04S/vite.config.ts',server:{host:'127.0.0.1',port:0,strictPort:false}});await server.listen();url=new URL('/tests/Browser/E5-04S/fixture.html',server.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await server?.close();});
test('метка «Прочитано» у входящего и «Непрочитано» в ленте',async({page})=>{
  await page.goto(url);
  await expect(page.getByText('Виджет · Новый',{exact:true})).toHaveCount(1);
  await expect(page.getByText(/✓ Прочитано · \d\d:\d\d/)).toHaveCount(1);
  await expect(page.getByText('Непрочитано',{exact:true})).toHaveCount(1);
  await expect(page.getByRole('button',{name:'Предложить ответ'})).toBeEnabled();
  await mkdir('.local/screenshots/SP-E5-04S',{recursive:true});
  await page.screenshot({path:'.local/screenshots/SP-E5-04S/dialog.png',fullPage:true});
});
