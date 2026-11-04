import { test, expect } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server:ViteDevServer;let url:string;
test.use({viewport:{width:1440,height:900},launchOptions:process.env.E109D_CHROMIUM_PATH?{executablePath:process.env.E109D_CHROMIUM_PATH}:{}});
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E1-09D/vite.config.ts',server:{host:'127.0.0.1',port:0,strictPort:false}});await server.listen();url=new URL('/tests/Browser/E1-09D/fixture.html',server.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await server?.close();});
test('сравнение изменений и фильтр периода',async({page})=>{await page.goto(url);await page.getByRole('button',{name:'Действия с записью'}).first().click();await page.getByRole('button',{name:'Детали действия',exact:true}).click();await expect(page.getByText('new → done')).toBeVisible();await mkdir('.local/screenshots/sp-e1-09d',{recursive:true});await page.screenshot({path:'.local/screenshots/sp-e1-09d/details.png',fullPage:true});await page.getByRole('button',{name:'Закрыть'}).click();await expect(page.getByRole('button',{name:'Применить'})).toHaveCount(0);await page.getByRole('combobox',{name:'Период'}).click();await page.getByRole('option',{name:'Свой диапазон'}).click();await expect(page.getByLabel('С даты')).toBeVisible();});
test('готовый период применяется сразу',async({page})=>{await page.goto(url);await page.route('**/journal?*',route=>route.abort());await page.getByRole('combobox',{name:'Период'}).click();const request=page.waitForRequest(req=>req.url().includes('/journal?')&&req.url().includes('from='));await page.getByRole('option',{name:'7 дней'}).click();expect((await request).url()).toContain('to=');});

