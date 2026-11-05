import {expect,test} from '@playwright/test';
import {createServer,type ViteDevServer} from 'vite';
import {mkdir} from 'node:fs/promises';
let server:ViteDevServer;let url:string;
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E4-01U/vite.config.ts',cacheDir:'.local/runtime/vite-e401u',server:{host:'127.0.0.1',port:0}});await server.listen();url=new URL('/tests/Browser/E4-01U/fixture.html',server.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await server.close();});
test.use({launchOptions:{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}});
test('подсказка отката и история версий',async({page})=>{await page.setViewportSize({width:1440,height:900});await page.goto(url);await expect(page.getByText('Откат создаёт новый черновик из выбранной версии. Активная публикация не изменится. Текущий черновик сохранится в копию.')).toBeVisible();const history=page.locator('section').filter({has:page.getByRole('heading',{name:'История публикаций'})});for(const name of ['Изменений','Диалогов','Качество'])await expect(history.getByRole('columnheader',{name})).toBeVisible();await expect(history.getByRole('button',{name:'Откатить с сохранением'})).toBeEnabled();const changes=page.locator('section').filter({has:page.getByRole('heading',{name:'Изменения черновика'})});await expect(changes.locator('tbody tr').first().locator('td').nth(2)).toHaveText('—');await mkdir('.local/screenshots/SP-E4-01U',{recursive:true});await history.scrollIntoViewIfNeeded();await page.screenshot({path:'.local/screenshots/SP-E4-01U/history.png'});});
