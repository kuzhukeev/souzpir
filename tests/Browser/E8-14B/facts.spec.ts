import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let vite:ViteDevServer;
const base='http://127.0.0.1:5379/tests/Browser/E8-14B/fixture.html';
test.use({viewport:{width:1440,height:900},launchOptions:process.env.E814B_CHROMIUM_PATH?{executablePath:process.env.E814B_CHROMIUM_PATH}:{}});
test.beforeAll(async()=>{vite=await createServer({configFile:'tests/Browser/E8-14B/vite.config.ts'});await vite.listen();});
test.afterAll(async()=>{await vite?.close();});
test('исправление факта открывает редактор',async({page})=>{await page.goto(base+'?screen=facts');await page.getByRole('button',{name:/Добавить факт/}).click();await expect(page.getByText('Заголовок сайта',{exact:true})).toBeVisible();const field=page.getByLabel('С сайта');await field.fill('Исправленный факт');await page.getByRole('button',{name:'Готово'}).click();await expect(page.getByText('Исправленный факт')).toBeVisible();await expect(page.getByRole('button',{name:'Исправить',exact:true})).toBeVisible();await mkdir('.local/screenshots/sp-e8-14b',{recursive:true});await page.screenshot({path:'.local/screenshots/sp-e8-14b/facts.png',fullPage:true});});
