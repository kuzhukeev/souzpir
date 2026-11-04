import {test,expect} from '@playwright/test';
import {createServer,type ViteDevServer} from 'vite';
test.setTimeout(60000);let server:ViteDevServer;let url:string;
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E5-04F/vite.config.ts',server:{host:'127.0.0.1',port:0,strictPort:false}});await server.listen();url=new URL('/tests/Browser/E5-04F/fixture.html',server.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await server?.close();});
test('аватар, ожидание и подсказка поиска',async({page})=>{await page.setViewportSize({width:1440,height:900});await page.goto(url);const list=page.getByRole('region',{name:'Список диалогов'});await expect(list.getByRole('img',{name:'Мария Иванова'})).toBeVisible();await expect(list.getByText('Ждёт ответа · 35 мин')).toBeVisible();await expect(page.getByPlaceholder('Имя, телефон, почта или сообщение')).toBeVisible();await page.screenshot({path:'.local/runtime/e5-04f-dialogs.png',fullPage:true});});
