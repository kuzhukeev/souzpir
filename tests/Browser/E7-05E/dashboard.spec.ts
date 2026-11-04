import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
let server: ViteDevServer; let fixture: string;
test.setTimeout(60000);
test.use({viewport:{width:1440,height:900},launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? {executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH} : {}});
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E7-05D/vite.config.ts',server:{host:'127.0.0.1',port:0}});await server.listen();fixture=new URL('/tests/Browser/E7-05E/fixture.html',server.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await server?.close();});
test('график контактов и нагрузка менеджеров',async({page})=>{
 await page.goto(fixture);
 await expect(page.locator('[aria-label="2026-10-01: диалогов 2, с контактом 1"]')).toBeVisible();
 const table=page.getByRole('table').filter({has:page.getByRole('columnheader',{name:'Менеджер'})});
 await expect(table.getByRole('row').filter({hasText:'Иван Петров'})).toContainText('2');
 await expect(table.getByRole('columnheader',{name:'Закрыто'})).toBeVisible();
 await page.screenshot({path:'.local/screenshots/SP-E7-05E/dashboard.png',fullPage:true});
 await page.goto(`${fixture}?error`);
 await expect(page.getByRole('alert')).toContainText('LOAD_ERROR');
});
test('фильтр и превью событий',async({page})=>{
 await page.goto(`${fixture}?attention`);
 await expect(page.getByRole('heading',{name:'На сводке'})).toBeVisible();
 await page.getByRole('combobox',{name:'Событие'}).selectOption('tasks');
 await expect(page.getByRole('table').getByText('Обещания просрочены')).toBeVisible();
 await expect(page.getByRole('table').getByText('Каналы выключены')).toHaveCount(0);
 await page.screenshot({path:'.local/screenshots/SP-E7-05E/attention.png',fullPage:true});
});
