import {test,expect} from '@playwright/test';
import {createServer,type ViteDevServer} from 'vite';
test.setTimeout(60000);
let server:ViteDevServer;let url:string;
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E5-04E/vite.config.ts',server:{host:'127.0.0.1',port:0,strictPort:false}});await server.listen();url=new URL('/tests/Browser/E5-04E/fixture.html',server.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await server?.close();});
test('фильтры, сортировка и страницы',async({page})=>{
 await page.setViewportSize({width:1440,height:900});await page.goto(url);
 await expect(page.getByLabel('Канал')).toHaveValue('');await expect(page.getByRole('option',{name:'Telegram'})).toHaveCount(1);
 await expect(page.getByLabel('Этап')).toBeVisible();await expect(page.getByRole('option',{name:'Ирина'})).toHaveCount(1);
 await expect(page.getByLabel('Сортировка')).toBeVisible();await expect(page.getByLabel('Страницы диалогов')).toContainText('Страница 1 из 2');
 await expect(page.getByRole('button',{name:'Назад',exact:true})).toBeDisabled();
 await page.screenshot({path:'.local/runtime/e5-04e-dialogs.png',fullPage:true});
 const req=page.waitForRequest(request=>request.url().includes('/seller/dialogs?') && request.url().includes('page=2'));
 await page.route('**/seller/dialogs?**',route=>route.fulfill({status:204}));await page.getByRole('button',{name:'Вперёд',exact:true}).click();await req;
});

