import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
test.setTimeout(60000);
let vite:ViteDevServer;let url:string;
test.use({viewport:{width:1440,height:900},launchOptions:process.env.E820B_CHROMIUM_PATH?{executablePath:process.env.E820B_CHROMIUM_PATH}:{}});
test.beforeAll(async()=>{vite=await createServer({configFile:'tests/Browser/E8-20B/vite.config.ts',server:{host:'127.0.0.1',port:0,strictPort:false}});await vite.listen();url=new URL('/tests/Browser/E8-20B/fixture.html',vite.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await vite?.close();});
test('сравнение четырёх компаний и вопросы',async({page})=>{await page.goto(url);await page.getByLabel('Первая компания').selectOption('Альфа');await page.getByLabel('Вторая компания').selectOption('Бета');await page.getByLabel('Третья компания').selectOption('Гамма');await page.getByLabel('Четвёртая компания').selectOption('Дельта');await expect(page.getByRole('heading',{name:'Упоминания по вопросам'})).toBeVisible();await expect(page.getByRole('columnheader',{name:'Дельта'})).toHaveCount(2);await expect(page.getByLabel('Четвёртая компания').getByRole('option',{name:'Альфа'})).toHaveAttribute('disabled','');await mkdir('.local/screenshots/sp-e8-20b',{recursive:true});await page.screenshot({path:'.local/screenshots/sp-e8-20b/comparison.png',fullPage:true});});

