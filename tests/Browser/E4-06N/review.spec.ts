import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer; let url: string;
test.setTimeout(60000);
test.use({viewport:{width:1440,height:900},launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{}});
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E4-06N/vite.config.ts',server:{host:'127.0.0.1',port:0}});await server.listen();url=new URL('/tests/Browser/E4-06N/fixture.html?imports',server.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await server?.close();});
test('separate review preserves actions and returns to documents',async({page})=>{
 await page.route('**/seller/knowledge/imports/preview',route=>route.fulfill({json:{proposals:['Условия доставки','Город получения'],document_id:'new',sheets:[],document:{id:'new',name:'facts.txt',type:'txt',pages:null,status:'previewed',fragments_count:2,has_source:true,created_at:'2026-11-01'}}}));
 for (const width of [1440,1024,390]) {
  await page.setViewportSize({width,height:900}); await page.goto(url);
  await page.getByLabel('Файл',{exact:true}).setInputFiles({name:'facts.txt',mimeType:'text/plain',buffer:Buffer.from('условия')});
  await page.getByRole('button',{name:'Посмотреть предложения'}).click();
  const heading=page.getByRole('heading',{name:'Сопоставление фактов'});await expect(heading).toBeFocused();
  await expect(page.getByRole('heading',{name:'Документы',exact:true})).toBeHidden();
  await page.getByRole('button',{name:'Принять все'}).click();await expect(page.getByLabel('Принять факт 1')).toBeChecked();
  await page.getByRole('button',{name:'Изменить',exact:true}).first().click();await page.getByLabel('Текст факта').fill('Исправленный факт');await page.getByRole('button',{name:'Готово'}).click();
  await expect(page.getByText('Исправленный факт',{exact:true})).toBeVisible();
  expect(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+1)).toBe(true);
  if(width===1440){await mkdir('.local/screenshots/SP-E4-06N',{recursive:true});await page.screenshot({path:'.local/screenshots/SP-E4-06N/review.png',fullPage:true});}
  await page.getByRole('button',{name:'К документам'}).click();await expect(page.getByRole('heading',{name:'Документы',exact:true})).toBeVisible();
  await expect(page.getByRole('heading',{name:'Документы и импорт'})).toBeFocused();
  await expect(page.getByRole('row').filter({hasText:'facts.txt'})).toContainText('2');
  await page.getByRole('button',{name:'Вернуться к сопоставлению'}).click();
  await expect(page.getByText('Исправленный факт',{exact:true})).toBeVisible();
  await expect(page.getByLabel('Принять факт 1')).toBeChecked();
  await page.getByRole('button',{name:'Отклонить',exact:true}).first().click();
  await page.getByRole('button',{name:'Отклонить',exact:true}).first().click();
  await expect(page.getByRole('status')).toContainText('Предложений больше нет.');
 }
});
