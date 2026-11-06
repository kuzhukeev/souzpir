import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer; let url: string;
test.setTimeout(60000);
test.use({viewport:{width:1440,height:900},launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{}});
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E7-04T/vite.config.ts'});await server.listen();url=new URL('/tests/Browser/E7-04T/fixture.html',server.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await server?.close();});
test('получатели добавляются, удаляются и передаются в подписку',async({page})=>{
 await page.goto(url);await page.getByRole('button',{name:'Отчёты',exact:true}).click();
 const section=page.locator('#report');const entry=section.getByLabel('Получатели');
 await expect(section.getByText('test@example.test',{exact:true})).toBeVisible();
 await entry.fill('bad');await entry.press('Enter');await expect(section.getByRole('status')).toContainText('корректный адрес');
 await entry.fill('new@example.test');await entry.press('Enter');await expect(section.getByText('new@example.test',{exact:true})).toBeVisible();
 await section.getByRole('button',{name:'Удалить test@example.test'}).click();
 await expect(section.getByText('test@example.test',{exact:true})).toHaveCount(0);
 await entry.fill('pending@example.test');
 await mkdir('.local/screenshots/SP-E7-04T',{recursive:true});await page.screenshot({path:'.local/screenshots/SP-E7-04T/report.png',fullPage:true});
 let submitted: unknown;
 await page.route('**/seller/analytics/subscription',async route=>{submitted=route.request().postDataJSON();await route.fulfill({status:204});});
 await section.getByRole('button',{name:'Сохранить подписку'}).click();
 await expect.poll(()=>submitted).toEqual({day:'wed',recipients:['new@example.test','pending@example.test']});
 await expect(section.getByRole('link',{name:'Скачать PDF'})).toHaveAttribute('href',/report_type=weekly/);
 await expect(section.getByRole('link',{name:'Скачать CSV'})).toHaveAttribute('href',/manager=u/);
});
test('подписка доступна во всех темах и ширинах',async({page})=>{
 for(const [theme,width] of [['neutral',1440],['confectionery',1024],['pyro',390]] as const){await page.setViewportSize({width,height:900});await page.goto(url+'?theme='+theme);await page.getByRole('button',{name:'Отчёты',exact:true}).click();await expect(page.getByLabel('Получатели')).toBeVisible();expect(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth)).toBe(true);}
});

test('пользователь без прав управления не видит отправку и подписку',async({page})=>{
 await page.goto(url+'?readonly=1');await page.getByRole('button',{name:'Отчёты',exact:true}).click();
 await expect(page.getByRole('heading',{name:'Подписка на отчёт'})).toHaveCount(0);
 await expect(page.getByRole('button',{name:'Отправить тестовый отчёт'})).toHaveCount(0);
 await expect(page.getByRole('link',{name:'Скачать PDF'})).toBeVisible();
});

test('кнопка тестовой отправки сохраняет выбранные фильтры',async({page})=>{
 await page.goto(url);await page.getByRole('button',{name:'Отчёты',exact:true}).click();
 const section=page.locator('#report');await section.getByLabel('Тип отчёта').selectOption('topics');
 await section.getByLabel('Почта получателя').fill('report@example.test');
 let submitted: unknown;
 await page.route('**/seller/analytics/send',async route=>{submitted=route.request().postDataJSON();await route.fulfill({status:204});});
 await section.getByRole('button',{name:'Отправить тестовый отчёт'}).click();
 await expect.poll(()=>submitted).toMatchObject({email:'report@example.test',report_type:'topics',from:'2026-11-01',to:'2026-11-03',manager:'u'});
});
