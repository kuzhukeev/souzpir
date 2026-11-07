import {test,expect} from '@playwright/test';
import {createServer,type ViteDevServer} from 'vite';
test.setTimeout(60000);let server:ViteDevServer;let url:string;
test.use({launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{}});
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E8-18I/vite.config.ts',server:{host:'127.0.0.1',port:0,strictPort:false}});await server.listen();url=new URL('/tests/Browser/E8-18I/fixture.html',server.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await server?.close();});
test('выводы, печать, таблица компаний и проверенное присутствие',async({page})=>{
 const rev='a'.repeat(64);const sections={site:null,competitors:[{name:'Наш бренд',answers:3,is_brand:true}],gaps:[{intent:'choose',text:'Как выбрать поставщика?'}],facts:[],sources:[{domain:'one.test',answers:3,type:'brand_site',presence:true},{domain:'two.test',answers:1,type:'competitor_site',presence:false},{domain:'three.test',answers:1,type:'other',presence:null}],actions:[{title:'Обновить сайт',why:'Найден пробел'}],changes:null};
 const data={revision:rev,confirmed:[],reports:[],report:{id:'r',measurement_id:'m',visibility:25,visibility_kind:'full',coverage:100,share_of_voice:null,position:null,verified_errors:0,providers:[],questions:2,evidence_status:'available',sections}};
 await import('node:fs/promises').then(fs=>fs.mkdir('.local/screenshots/SP-E8-18I',{recursive:true}));await page.route('**/vision/report/data**',route=>route.fulfill({json:data}));await page.route('**/vision/runs/revision',route=>route.fulfill({json:{revision:rev}}));
 await page.addInitScript(()=>{window.print=()=>{document.body.dataset.printed='yes';};});await page.setViewportSize({width:1440,height:900});await page.goto(url);
 await expect(page.getByRole('heading',{name:'Главные выводы'})).toBeVisible();await expect(page.getByRole('table').getByText('Наш бренд · Ваш бренд')).toBeVisible();
 await expect(page.getByText(/Ваш сайт — 1/)).toBeVisible();await expect(page.getByRole('columnheader',{name:'Цитирований'})).toBeVisible();await expect(page.getByRole('link',{name:'Перейти к действиям →'})).toBeVisible();await expect(page.getByText('Бренд присутствует',{exact:false})).toBeVisible();await expect(page.getByText('Бренд не указан',{exact:false})).toBeVisible();await expect(page.getByText('Присутствие не проверено',{exact:false})).toBeVisible();
 await expect(page.getByRole('link',{name:'Все источники →'})).toHaveAttribute('href','/vision/sources');await page.getByText('Выбор и сравнение',{exact:true}).click();await expect(page.getByText('Как выбрать поставщика?')).toBeVisible();await page.getByRole('button',{name:'Скачать отчёт'}).click();await page.getByRole('menuitem',{name:'Печать / PDF'}).click();await expect(page.locator('body')).toHaveAttribute('data-printed','yes');await page.screenshot({path:'.local/screenshots/SP-E8-18I/report.png',fullPage:true});
});
