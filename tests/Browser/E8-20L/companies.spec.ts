import {expect,test} from '@playwright/test';
import {createServer,type ViteDevServer} from 'vite';
import {mkdir} from 'node:fs/promises';
let server:ViteDevServer;let url:string;
test.use({viewport:{width:1440,height:900},launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{}});
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E8-20L/vite.config.ts',server:{host:'127.0.0.1',port:0}});await server.listen();url=new URL('/tests/Browser/E8-20L/fixture.html',server.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await server?.close();});
test('вкладки, пустые состояния и сопоставимая история',async({page})=>{
 await page.goto(url+'?edit=1');
 await expect(page.getByRole('columnheader',{name:'Источники (до 3)'})).toBeVisible();
 await page.getByRole('button',{name:'Отслеживаемые',exact:true}).click();
 await expect(page.getByText('Пока нет отслеживаемых компаний.')).toBeVisible();
 await page.getByRole('button',{name:'Из анкеты, но не названы'}).click();
 await expect(page.locator('li').filter({hasText:'Из анкеты'})).toBeVisible();
 await page.getByRole('button',{name:'Все названные',exact:true}).click();
 const detail=page.locator('details').filter({has:page.locator('summary').filter({hasText:'Альфа'})}).first();
 await detail.locator('summary').click();
 await expect(detail.getByRole('figure',{name:/Динамика числа ответов/})).toBeVisible();
 await expect(page.getByRole('heading',{name:'Предложенные группы'})).toBeVisible();
 await mkdir('.local/screenshots/sp-e8-20l',{recursive:true});await page.screenshot({path:'.local/screenshots/sp-e8-20l/companies.png',fullPage:true});
});


test('поиск и доступ только для чтения',async({page})=>{
 await page.goto(url);
 await page.getByPlaceholder('Название компании',{exact:true}).fill('нет такой компании');
 await expect(page.getByText('Компании не найдены.')).toBeVisible();
 await expect(page.getByRole('heading',{name:'Предложенные группы'})).toHaveCount(0);
 await expect(page.getByRole('button',{name:'Отслеживать',exact:true})).toHaveCount(0);
});
