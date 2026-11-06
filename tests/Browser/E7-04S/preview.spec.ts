import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server:ViteDevServer;let url:string;
test.setTimeout(60000);
test.use({viewport:{width:1440,height:900},launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{}});
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E7-04S/vite.config.ts',server:{host:'127.0.0.1',port:0,strictPort:false}});await server.listen();url=new URL('/tests/Browser/E7-04S/fixture.html',server.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await server?.close();});
test('предпросмотр содержит бренд, имя менеджера и выбранный вид',async({page})=>{
 await page.goto(url);await page.getByRole('button',{name:'Отчёты',exact:true}).click();
 const report=page.locator('#report');await expect(report.getByText('Пример бренда',{exact:true})).toBeVisible();
 await expect(report.getByText(/менеджер: Ирина Иванова/)).toBeVisible();
 await expect(report.getByRole('region',{name:'Динамика диалогов'})).toContainText('2026-11-01');
 await expect(report.getByRole('link',{name:'Скачать CSV'})).toHaveAttribute('href',/manager=u/);
 for(const type of ['weekly','dialogs','topics','managers']){await report.getByLabel('Тип отчёта').selectOption(type);await expect(report.getByRole('link',{name:'Скачать PDF'})).toHaveAttribute('href',new RegExp('report_type='+type));}
 await mkdir('.local/screenshots/SP-E7-04S',{recursive:true});await page.screenshot({path:'.local/screenshots/SP-E7-04S/preview.png',fullPage:true});
});

test('предпросмотр и подписка доступны во всех темах и ширинах',async({page})=>{
 for(const [theme,width] of [['neutral',1440],['confectionery',1024],['pyro',390]] as const){
  await page.setViewportSize({width,height:900});await page.goto(url+'?theme='+theme);await page.getByRole('button',{name:'Отчёты',exact:true}).click();
  await expect(page.locator('html')).toHaveAttribute('data-theme',theme);
  await expect(page.getByRole('heading',{name:'Подписка на отчёт'})).toBeVisible();
  expect(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth)).toBe(true);
 }
});
