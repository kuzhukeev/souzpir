import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let vite:ViteDevServer;
test.use({viewport:{width:1440,height:900},launchOptions:process.env.E102C_CHROMIUM_PATH?{executablePath:process.env.E102C_CHROMIUM_PATH}:{}});
test.beforeAll(async()=>{vite=await createServer({configFile:'tests/Browser/E1-02C/vite.config.ts'});await vite.listen();});
test.afterAll(async()=>{await vite?.close();});
test('сфера, оболочка и несколько названий',async({page})=>{const base='http://127.0.0.1:5399/tests/Browser/E1-02C/fixture.html';await page.goto(base);await expect(page.getByLabel('Сфера деятельности')).toHaveValue('Пиротехника');await expect(page.getByRole('button',{name:'Свернуть меню'})).toBeAttached();await page.goto(base+'?step=2');const alias=page.getByLabel('Как клиенты вас называют');await alias.fill('Один');await page.getByRole('button',{name:'Добавить название'}).click();await alias.fill('Два');await alias.press('Enter');await expect(page.getByRole('button',{name:'Удалить Один'})).toBeVisible();await expect(page.getByRole('button',{name:'Удалить Два'})).toBeVisible();await mkdir('.local/screenshots/sp-e1-02c',{recursive:true});await page.screenshot({path:'.local/screenshots/sp-e1-02c/brand.png',fullPage:true});await page.getByRole('button',{name:'Удалить Один'}).click();await expect(page.getByRole('button',{name:'Удалить Один'})).toHaveCount(0);});
