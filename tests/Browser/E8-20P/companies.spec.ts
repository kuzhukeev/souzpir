import {expect,test} from '@playwright/test';
import {createServer,type ViteDevServer} from 'vite';
import {mkdir} from 'node:fs/promises';
let server:ViteDevServer;let url:string;
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E8-20P/vite.config.ts',cacheDir:'.local/runtime/vite-e820p',server:{host:'127.0.0.1',port:0}});await server.listen();url=new URL('/tests/Browser/E8-20P/fixture.html',server.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await server.close();});
test.use({launchOptions:{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}});
test('сравнение закрепляет свой бренд, источники отображаются таблицей',async({page})=>{await page.setViewportSize({width:1440,height:900});await page.goto(url);await expect(page.getByText('Вы: Альфа',{exact:true})).toBeVisible();await page.getByLabel('Первая компания').selectOption('Альфа ООО');await expect(page.getByRole('heading',{name:'Сравнить компании'})).toBeVisible();await page.getByRole('button',{name:'Добавить конкурента'}).click();await expect(page.getByLabel('Третья компания')).toBeVisible();const detail=page.locator('details').filter({has:page.locator('summary').filter({hasText:'Альфа ООО'})}).first();await detail.locator('summary').click();await expect(detail.getByRole('columnheader',{name:'Источник'})).toBeVisible();await expect(detail.getByRole('cell',{name:'example.test'})).toBeVisible();await mkdir('.local/screenshots/SP-E8-20P',{recursive:true});await detail.scrollIntoViewIfNeeded();await page.screenshot({path:'.local/screenshots/SP-E8-20P/company.png'});});
