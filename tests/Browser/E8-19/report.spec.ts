import {expect,test} from '@playwright/test'; import {createServer,type ViteDevServer} from 'vite';
test.setTimeout(60000); let server:ViteDevServer; let fixture:string;
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E8-19/vite.config.ts',server:{host:'127.0.0.1',port:0}});await server.listen();fixture=new URL('/tests/Browser/E8-19/fixture.html',server.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await server?.close();});
test.use({launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{}});
test('сайт, действия, динамика и отзыв',async({page})=>{
 await page.setViewportSize({width:1440,height:900}); await page.goto(fixture);
 await expect(page.getByText('Оценка по проверке сайта',{exact:false})).toBeVisible();
 await expect(page.getByText('Приоритет: Высокий')).toBeVisible(); await expect(page.getByText('Шаг три')).toBeVisible();
 await expect(page.getByRole('link',{name:'Открыть динамику →'})).toHaveAttribute('href','/vision/trends');
 await expect(page.locator('#method details')).toHaveCount(4);
 await page.locator('#actions').scrollIntoViewIfNeeded(); await page.screenshot({path:'.local/screenshots/sp-e8-19/report.png'});
 await page.getByRole('button',{name:'Поделиться отчётом'}).click();
 await expect(page.getByText('Публичный доступ: включён')).toBeVisible(); await expect(page.getByRole('button',{name:'Отозвать ссылку'})).toBeVisible();
 await page.screenshot({path:'.local/screenshots/sp-e8-19/share.png'});
 await page.getByRole('button',{name:'Готово'}).click(); await expect(page.getByRole('dialog')).toHaveCount(0);
 await page.route('**/vision/actions', route => route.fulfill({status:422,contentType:'application/json',body:'{}'}));
 const request=page.waitForRequest('**/vision/actions'); await page.getByRole('button',{name:'Взять в работу'}).click();
 expect((await request).postDataJSON().title).toBe('Обновить сайт');

});
