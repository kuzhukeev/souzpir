import {expect,test} from '@playwright/test';
import {createServer,type ViteDevServer} from 'vite';
import {mkdir} from 'node:fs/promises';
let vite:ViteDevServer;let base:string;
test.use({viewport:{width:1440,height:900},launchOptions:process.env.E102D_CHROMIUM_PATH?{executablePath:process.env.E102D_CHROMIUM_PATH}:{}});
test.beforeAll(async()=>{vite=await createServer({configFile:'tests/Browser/E1-02H/vite.config.ts'});await vite.listen();const address=vite.httpServer?.address();if(!address||typeof address==='string')throw new Error('Vite address missing');base=`http://127.0.0.1:${address.port}`;});
test.afterAll(async()=>{await vite?.close();});
test('выбор компании и поиск',async({page})=>{await page.goto(`${base}/tests/Browser/E1-02H/fixture.html`);await expect(page.getByRole('heading',{name:'Выберите компанию'})).toBeVisible();await expect(page.getByText('Доступно компаний: 1')).toBeVisible();await expect(page.getByText('Последний вход: впервые')).toBeVisible();await expect(page.getByRole('link',{name:/Союз Пир Владелец/})).toHaveAttribute('href','/dashboard');await mkdir('.local/screenshots/sp-e1-02h',{recursive:true});await page.screenshot({path:'.local/screenshots/sp-e1-02h/company.png',fullPage:true});await page.getByLabel('Поиск по названию').fill('нет');await expect(page.getByText('Ничего не найдено')).toBeVisible();await page.getByRole('button',{name:'Сбросить фильтр'}).click();await expect(page.getByText('Последний вход: впервые')).toBeVisible();});
