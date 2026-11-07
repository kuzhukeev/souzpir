import {expect,test} from '@playwright/test';
import {createServer,type ViteDevServer} from 'vite';
let server:ViteDevServer;let url:string;
test.setTimeout(60000);
test.use({launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{}});
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E8-19D/vite.config.ts'});await server.listen();url=new URL('/tests/Browser/E8-19D/internal.html',server.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await server?.close();});
test('разделы отчёта показывают подтверждённые данные и пустые состояния',async({page})=>{
 await page.goto(url);await expect(page.getByRole('heading',{name:'Уточнить описание товаров'})).toBeVisible();
 await expect(page.getByText('Шаги пока не указаны.')).toBeVisible();
 await expect(page.getByRole('button',{name:'Взять в работу'})).toHaveCount(0);
 for(const width of [1440,1024,390]){await page.setViewportSize({width,height:900});expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1)).toBe(true);}
 await page.setViewportSize({width:1440,height:900});await page.screenshot({path:'.local/screenshots/sp-e8-19d/internal.png',fullPage:true});
 await page.goto(url+'?empty');await expect(page.getByText('Подтверждённых действий пока нет.')).toBeVisible();
 await expect(page.getByText('Для сайта из снимка нет подходящей проверки на дату отчёта.')).toBeVisible();
});
