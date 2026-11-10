import {test,expect} from '@playwright/test';
import {createServer,type ViteDevServer} from 'vite';
import axe from 'axe-core';
let server:ViteDevServer;let url:string;
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E6-03Z/vite.config.ts',server:{host:'127.0.0.1',port:0,strictPort:false}});await server.listen();url=new URL('/tests/Browser/E6-03Z/stats.html',server.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await server?.close();});
test('статистика загружается отдельно и доступна повторная попытка',async({page})=>{
 let release!:()=>void;const gate=new Promise<void>(resolve=>{release=resolve;});let count=0;
 await page.route('**/seller/settings/policy',async route=>{count++;if(count===1){await gate;await route.abort();return;}await route.fulfill({status:200,headers:{'X-Inertia':'true','Content-Type':'application/json'},body:JSON.stringify({component:'Fixture',props:{followupStats:{sent:3,replies:[1,2]}},url:'/seller/settings/policy',version:null})});});
 await page.goto(url);const stats=page.getByRole('region',{name:'Статистика напоминаний'});
 await expect(stats.locator('[aria-busy="true"]')).toBeVisible();await expect(page.getByLabel('Первый интервал, часов')).toBeEnabled();
 release();await expect(stats.getByText('Не удалось загрузить статистику')).toBeVisible();await stats.getByRole('button',{name:'Повторить'}).click();await expect(stats.getByText('Отправлено: 3')).toBeVisible();await expect(stats.getByText('Ответили после второго: 2')).toBeVisible();
 await page.addScriptTag({content:axe.source});
 const violations=await page.evaluate(async()=>(await (window as unknown as {axe:{run:(root:Document,options:object)=>Promise<{violations:{id:string}[]}>}}).axe.run(document,{runOnly:{type:'tag',values:['wcag2a','wcag2aa','wcag21aa']}})).violations.map(item=>item.id));
 expect(violations).toEqual([]);
});
