import {expect,test} from '@playwright/test';
import {createServer,type ViteDevServer} from 'vite';
import {mkdir} from 'node:fs/promises';
let server: ViteDevServer; let url: string;
test.use({viewport:{width:1440,height:900},launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? {executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH} : {}});
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E1-04C/vite.config.ts',cacheDir:'.local/runtime/vite-e104c',server:{host:'127.0.0.1',port:0,strictPort:false}});await server.listen();url=new URL('/tests/Browser/E1-04C/fixture.html',server.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await server?.close();});
test('модули показывают дату, состав и срок, компания не удаляется',async({page})=>{await mkdir('.local/screenshots/SP-E1-04C',{recursive:true});await page.goto(url);await expect(page.getByText('Что входит')).toHaveCount(2);await expect(page.getByText(/Подключён с 12\.10\.2026/)).toHaveCount(2);await page.screenshot({path:'.local/screenshots/SP-E1-04C/modules.png',fullPage:true});await page.getByRole('switch',{name:'Отключить Видение'}).click();await expect(page.getByText(/не менее 90 дней/)).toBeVisible();await page.screenshot({path:'.local/screenshots/SP-E1-04C/disable.png',fullPage:true});await page.goto(`${url}?company`);await expect(page.getByText('Данные компании')).toBeVisible();await expect(page.getByText('Удалить компанию')).toHaveCount(0);await page.screenshot({path:'.local/screenshots/SP-E1-04C/company.png',fullPage:true});});
