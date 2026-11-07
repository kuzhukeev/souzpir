import {test,expect} from '@playwright/test';
import {createServer,type ViteDevServer} from 'vite';
test.setTimeout(60000);let server:ViteDevServer;let url:string;
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E8-15/vite.config.ts',server:{host:'127.0.0.1',port:0,strictPort:false}});await server.listen();url=new URL('/tests/Browser/E8-15/pages-fixture.html',server.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await server?.close();});
test('частота и план ответов без неявного расписания',async({page})=>{
 const questions=[{id:'1',text:'Где заказать товары?',intent:'choose'}];
 const active={id:'set',version:2,questions,size:1,checksum:'a'.repeat(64),providers:{gemini:'google/gemini'},frequency:'weekly',active:true,approved_by:'u',approver_name:'Иван',created_at:'2026-10-31T12:00:00Z'};
 const data={candidates:questions.map(q=>({...q,status:'active',selected:true,origin:'manual',can_delete:true})),active_set:active,history:[active],limits:{questions:null,models:null},models:[{id:'google/gemini',provider:'gemini',status:'active'}],generation:{status:'blocked'},can_edit:true,has_profile:true};
 await page.route('**/vision/questions/data',route=>route.fulfill({json:data}));
 await page.setViewportSize({width:1440,height:900});await page.goto(url+'?screen=approve');
 await expect(page.getByLabel('Частота')).toHaveValue('weekly');await page.getByLabel('Частота').selectOption('monthly');
 await page.getByRole('checkbox',{name:'google/gemini'}).check();await expect(page.getByText('План ответов: 1 × 1 × 1 = 1 (вопросы × модели × прогоны)')).toBeVisible();
 await expect(page.getByText(/Автоматический запуск включается отдельно/)).toBeVisible();await page.screenshot({path:'.local/runtime/e8-15-approve.png',fullPage:true});
 await page.goto(url+'?screen=active');await expect(page.getByText('Частота: Еженедельно')).toBeVisible();
});
