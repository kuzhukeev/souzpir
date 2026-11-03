import {expect,test} from '@playwright/test'; import {createServer,type ViteDevServer} from 'vite';
test.setTimeout(60000);let server:ViteDevServer;let fixture:string;
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E5-04H/vite.config.ts',server:{host:'127.0.0.1',port:0}});await server.listen();fixture=new URL('/tests/Browser/E5-04H/fixture.html',server.resolvedUrls!.local[0]).href;});test.afterAll(async()=>{await server?.close();});
test.use({launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{}});
test('очередь, пагинация и запрос проверки',async({page})=>{await page.setViewportSize({width:1440,height:900});await page.goto(fixture);
 await expect(page.getByText('Требует проверки')).toBeVisible();await expect(page.getByText('Отправка и отклонение ответов пока недоступны',{exact:false})).toBeVisible();
 await page.screenshot({path:'.local/screenshots/sp-e5-04h/pending.png'});await page.getByRole('button',{name:'Далее'}).click();await expect(page.getByText('Обрабатывается')).toBeVisible();
 await page.getByRole('button',{name:'Запросить проверку'}).click();const dialog=page.getByRole('dialog');await expect(dialog.getByText('Ход будет помещён',{exact:false})).toBeVisible();await dialog.getByRole('button',{name:'Запросить проверку'}).click();await expect(page.getByText('Запрос на ручную проверку создан.')).toBeVisible();
 await page.setViewportSize({width:390,height:844});await expect(page.getByRole('heading',{name:'Очередь на одобрение'})).toBeVisible();});
