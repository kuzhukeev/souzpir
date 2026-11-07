import {expect,test} from '@playwright/test'; import {createServer,type ViteDevServer} from 'vite';
test.setTimeout(60000);let server:ViteDevServer;let fixture:string;
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E5-04N/vite.config.ts',server:{host:'127.0.0.1',port:0}});await server.listen();fixture=new URL('/tests/Browser/E5-04N/fixture.html',server.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await server?.close();});
test.use({launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{}});

test('очередь подсказок: действия, пагинация, мобильный режим',async({page})=>{
 await page.setViewportSize({width:1440,height:900});
 await page.goto(fixture);
 await expect(page.getByRole('heading',{name:'Готовые подсказки (3)'})).toBeVisible();
 await expect(page.getByText('Обновляется автоматически')).toBeVisible();
 await expect(page.getByText('1–2 из 3')).toBeVisible();
 const first=page.locator('li',{hasText:'Алексей Смирнов'});
 const second=page.locator('li',{hasText:'Анна Волкова'});
 await expect(first).toBeVisible();
 await expect(second).toBeVisible();
 await expect(page.getByText('Игорь Фомин')).toHaveCount(0);
 await page.screenshot({path:'.local/screenshots/sp-e5-04n/queue.png'});
 await first.getByRole('button',{name:'Пропустить'}).click();
 await expect(page.getByRole('heading',{name:'Готовые подсказки (2)'})).toBeVisible();
 await expect(first).toHaveCount(0);

 await page.setViewportSize({width:390,height:844});
 await expect(page.getByText('1 из 2')).toBeVisible();
 await expect(second).toBeVisible();
 await second.getByRole('button',{name:'Отправить',exact:true}).click();
 await expect(page.getByRole('heading',{name:'Готовые подсказки (1)'})).toBeVisible();
});
