import {test,expect} from '@playwright/test';
import {createServer,type ViteDevServer} from 'vite';
let server:ViteDevServer; let url:string;
test.setTimeout(60000);
test.use({viewport:{width:1440,height:900},launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{}});
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E7-04/vite.config.ts',server:{host:'127.0.0.1',port:0}});await server.listen();url=new URL('/tests/Browser/E7-04D/details.html',server.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await server?.close();});
test('воронка, темы, менеджеры и отчёт',async({page})=>{
 await page.addInitScript(()=>{window.print=()=>{document.body.dataset.printed='yes';};});
 await page.goto(url);
 await expect(page.getByRole('navigation',{name:'Разделы аналитики',exact:true}).getByRole('link')).toHaveCount(6);
 await expect(page.getByRole('combobox',{name:'Менеджер',exact:true})).toBeVisible();
 await expect(page.getByRole('heading',{name:'Итоги диалогов'})).toBeVisible();
 await page.screenshot({path:'.local/screenshots/SP-E7-04D/funnel.png'});
 await expect(page.getByText('Не знаю: 50% диалогов (по сохранённым задачам).')).toBeVisible();
 await page.getByRole('heading',{name:'Темы и вопросы'}).scrollIntoViewIfNeeded();
 await page.screenshot({path:'.local/screenshots/SP-E7-04D/topics.png'});
 await expect(page.getByRole('heading',{name:'Работа менеджеров'})).toBeVisible();
 await expect(page.getByText('15 мин')).toBeVisible();
 await page.getByRole('heading',{name:'Отчёт',exact:true}).scrollIntoViewIfNeeded();
 await expect(page.getByRole('textbox',{name:'Почта получателя'})).toBeVisible();
 await expect(page.getByRole('button',{name:'Отправить тестовый отчёт'})).toBeVisible();
 await page.screenshot({path:'.local/screenshots/SP-E7-04D/report.png'});
 await page.getByRole('button',{name:'Печать / PDF'}).click();
 await expect(page.locator('body')).toHaveAttribute('data-printed','yes');
 expect((await page.pdf({format:'A4'})).subarray(0,5).toString()).toBe('%PDF-');
});

