import {expect,test} from '@playwright/test';
import {createServer,type ViteDevServer} from 'vite';
import {mkdir} from 'node:fs/promises';
let server:ViteDevServer;let url:string;
test.setTimeout(60000);test.use({viewport:{width:1440,height:900},launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{}});
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E6-03J/vite.config.ts'});await server.listen();url=new URL('/tests/Browser/E6-03J/fixture.html',server.resolvedUrls!.local[0]).href;});test.afterAll(async()=>{await server?.close();});
test('время создания не выдаётся за время отправки',async({page})=>{await page.goto(url);const row=page.getByRole('row').filter({hasText:'Анна'});await expect(row.getByText('Нет данных',{exact:true})).toBeVisible();await expect(row.getByText('Ожидает отправки')).toBeVisible();await expect(page.getByText(/Массовая отправка недоступна/)).toBeVisible();await mkdir('.local/screenshots/SP-E6-03J',{recursive:true});await page.screenshot({path:'.local/screenshots/SP-E6-03J/followups.png',fullPage:true});});
test('пустой список показывает отсутствие кандидатов',async({page})=>{await page.goto(url+'?empty');await expect(page.getByText('За выбранный период напоминаний нет.')).toBeVisible();});

test('без права управления отмена скрыта',async({page})=>{await page.goto(url+'?readonly');await expect(page.getByRole('button',{name:'Отменить'})).toHaveCount(0);});
