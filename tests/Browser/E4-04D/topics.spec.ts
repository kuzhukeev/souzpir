import {expect,test} from '@playwright/test';
import {createServer,type ViteDevServer} from 'vite';
test.setTimeout(60000); let server:ViteDevServer; let fixture:string;
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E4-04D/vite.config.ts',server:{host:'127.0.0.1',port:0}});await server.listen();fixture=new URL('/tests/Browser/E4-04D/fixture.html',server.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await server?.close();});
test.use({launchOptions:{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}});
test('процент ответов и удаление факта из темы',async ({page})=>{
 await page.setViewportSize({width:1440,height:900});
 await page.goto(fixture);
 await expect(page.getByText('50 %')).toBeVisible();
 await page.screenshot({path:'.local/screenshots/sp-e4-04d/topics.png',fullPage:true});
 await page.getByRole('button',{name:'Редактировать'}).click();
 await expect(page.getByText('Черновик',{exact:true})).toBeVisible();
 await expect(page.getByRole('button',{name:'Убрать факт: Условия доставки'})).toBeVisible();
 await page.screenshot({path:'.local/screenshots/sp-e4-04d/topic.png',fullPage:true});
 await page.getByRole('button',{name:'Убрать факт: Условия доставки'}).click();
 await expect(page.getByRole('button',{name:'Убрать факт: Условия доставки'})).toHaveCount(0);
});
