import {test,expect} from '@playwright/test';
import {createServer,type ViteDevServer} from 'vite';
test.setTimeout(60000);
let server:ViteDevServer;let url:string;
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E8-13/vite.config.ts',server:{host:'127.0.0.1',port:0,strictPort:false}});await server.listen();url=new URL('/tests/Browser/E8-13/fixture.html',server.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await server?.close();});
test('уточнения доступны из замеров и отчёта; обещание добавить алиас убрано',async({page})=>{
 await page.setViewportSize({width:1440,height:900});
 await page.goto(url); await expect(page.getByRole('link',{name:/Уточнить замер/})).toHaveAttribute('href',/\/vision\/runs\/.*\/clarify\/mentions/);
 await page.screenshot({path:'.local/runtime/e8-13-clarifications.png',fullPage:true});
 await page.goto(url+'?screen=wizard'); await expect(page.getByText('Пример названия')).toBeVisible(); await expect(page.getByText(/Отметить для добавления/)).toHaveCount(0);
 for(const screen of ['runs','report']) {await page.goto(url+'?screen='+screen);await expect(page.getByRole('link',{name:'Уточнения'}).first()).toHaveAttribute('href','/vision/clarifications');}
 await page.goto(url+'?screen=actions');await expect(page.getByRole('link',{name:'Посмотреть эффект'})).toHaveAttribute('href','/vision/actions/effect');
});
