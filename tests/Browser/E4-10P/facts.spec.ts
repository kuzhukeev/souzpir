import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server:ViteDevServer; let url:string;
test.setTimeout(60000);
test.use({viewport:{width:1440,height:900},launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{}});
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E4-10P/vite.config.ts',server:{host:'127.0.0.1',port:0,strictPort:false}});await server.listen();url=new URL('/tests/Browser/E4-10P/fixture.html',server.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await server?.close();});
test('дерево, статусы и меню доступны с клавиатуры',async({page})=>{
 await page.goto(url+'?statuses=1');
 const list=page.getByRole('heading',{name:'Список фактов'}).locator('..');
 for(const text of ['Новый','Опубликован','Отключён','Изменён в черновике']) await expect(list.getByText(text,{exact:true})).toBeVisible();
 const menu=list.getByLabel('Действия: Доставка по России'); await menu.focus(); await page.keyboard.press('Enter');
 await expect(list.getByRole('link',{name:'Редактировать'})).toBeVisible();
 await expect(list.getByRole('button',{name:'Отключить',exact:true})).toBeVisible();
 await expect(list.getByRole('button',{name:'Удалить',exact:true})).toBeVisible();
 await expect(list.getByRole('button',{name:'Перенести',exact:true})).toBeDisabled();
 await page.keyboard.press('Escape'); await expect(menu).toBeFocused();
 await menu.press('Enter'); await list.getByRole('combobox',{name:'Перенести в тему'}).last().selectOption('payment');
 await page.route('**/seller/knowledge/facts/bulk',route=>route.abort());
 const request=page.waitForRequest(req=>req.url().endsWith('/seller/knowledge/facts/bulk')&&req.method()==='POST');
 await list.getByRole('button',{name:'Перенести',exact:true}).click();
 expect((await request).postDataJSON()).toMatchObject({version:3,action:'move',ids:['01ARZ3NDEKTSV4RRFFQ69G5FAV'],group:'payment'});
 await expect(list.getByRole('alert')).toContainText('Не удалось обновить факт');
 await mkdir('.local/screenshots/SP-E4-10P',{recursive:true}); await page.screenshot({path:'.local/screenshots/SP-E4-10P/facts.png',fullPage:true});
});
test('темы и ширины не создают горизонтального переполнения страницы',async({page})=>{
 for(const [theme,width] of [['neutral',1440],['confectionery',1024],['pyro',390]] as const){
  await page.setViewportSize({width,height:900});await page.goto(url+'?theme='+theme);
  await expect(page.locator('html')).toHaveAttribute('data-theme',theme);
  await expect(page.getByRole('navigation',{name:'Темы фактов'})).toBeVisible();
  expect(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth)).toBe(true);
 }
});
