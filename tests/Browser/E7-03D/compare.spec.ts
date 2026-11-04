import {test,expect} from '@playwright/test';
import {createServer,type ViteDevServer} from 'vite';
test.setTimeout(60000);let server:ViteDevServer;let url:string;
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E7-03D/vite.config.ts',server:{host:'127.0.0.1',port:0,strictPort:false}});await server.listen();url=new URL('/tests/Browser/E7-03D/fixture.html',server.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await server?.close();});
test('таблица сравнения и неизвестная версия знаний',async({page})=>{await page.setViewportSize({width:1440,height:900});await page.goto(url);await expect(page.getByRole('table')).toBeVisible();for(const header of ['Сценарий / критерий','До','После','Дельта','Что изменилось']) await expect(page.getByRole('columnheader',{name:header,exact:true})).toBeVisible();await expect(page.getByRole('cell',{name:'+1'})).toBeVisible();await expect(page.getByRole('option',{name:/версия не сохранена/})).toHaveCount(4);await page.screenshot({path:'.local/runtime/e7-03d-compare.png',fullPage:true});});
