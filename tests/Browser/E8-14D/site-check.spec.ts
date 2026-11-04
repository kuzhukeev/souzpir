import {expect,test} from '@playwright/test';
import {createServer,type ViteDevServer} from 'vite';
import {mkdir} from 'node:fs/promises';
let server:ViteDevServer;let url:string;
test.setTimeout(60000);test.use({viewport:{width:1440,height:900},launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{}});
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E8-14D/vite.config.ts'});await server.listen();url=new URL('/tests/Browser/E8-14D/fixture.html?screen=quiz',server.resolvedUrls!.local[0]).href;});test.afterAll(async()=>{await server?.close();});
test('проверка запускается через существующий маршрут и ошибки видны',async({page})=>{await page.route('**/vision/site/audit',route=>route.abort());await page.goto(url);const check=page.getByRole('button',{name:'Проверить сайт'});await expect(check).toBeEnabled();await mkdir('.local/screenshots/SP-E8-14D',{recursive:true});await page.screenshot({path:'.local/screenshots/SP-E8-14D/site.png',fullPage:true});const request=page.waitForRequest(r=>r.url().endsWith('/vision/site/audit')&&r.method()==='POST');await check.click();expect((await request).postDataJSON()).toEqual({url:'https://example.com'});await expect(page.getByRole('alert')).toContainText('Нет связи с сервером.');});

test('несохранённый адрес и отсутствие права блокируют запуск',async({page})=>{await page.goto(url);await page.getByLabel('Сайт',{exact:true}).fill('https://other.example');await expect(page.getByRole('button',{name:'Проверить сайт'})).toBeDisabled();await page.goto(url+'&readonly=1');await expect(page.getByRole('button',{name:'Проверить сайт'})).toHaveCount(0);});
