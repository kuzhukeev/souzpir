import { expect, test } from '@playwright/test';
import { spawn, type ChildProcess } from 'node:child_process';
import { mkdir } from 'node:fs/promises';
test.use({launchOptions:process.env.E505G_CHROMIUM_PATH?{executablePath:process.env.E505G_CHROMIUM_PATH}:{}});
let server:ChildProcess;
const url='http://127.0.0.1:5367/tests/Browser/E5-05G/fixture.html';
test.beforeAll(async()=>{server=spawn(process.execPath,['node_modules/vite/bin/vite.js','--config','tests/Browser/E5-05G/vite.config.ts'],{stdio:'ignore'});for(let i=0;i<100;i++){try{if((await fetch(url)).ok)return;}catch{/* Ждём сервер. */}await new Promise(resolve=>setTimeout(resolve,500));}throw new Error('Vite не запустился');});
test.afterAll(()=>server?.kill());
test('таблица, сортировка и сохранение режима',async({page})=>{await page.setViewportSize({width:1440,height:900});await page.goto(url);await expect(page.getByRole('columnheader')).toHaveCount(5);const rows=page.locator('tbody tr');await expect(rows.first()).toContainText('Бета');await page.getByRole('button',{name:/Срок/}).click();await expect(rows.first()).toContainText('Альфа');await mkdir('.local/screenshots/sp-e5-05g',{recursive:true});await page.screenshot({path:'.local/screenshots/sp-e5-05g/table.png',fullPage:true});await page.getByRole('button',{name:'Канбан'}).click();await page.goto(url);await expect(page.getByRole('button',{name:'Канбан'})).toHaveAttribute('aria-pressed','true');await page.getByRole('button',{name:'Список'}).click();await page.setViewportSize({width:390,height:844});await expect(rows.first()).toBeVisible();expect(await page.evaluate(()=>document.documentElement.scrollWidth <= window.innerWidth)).toBeTruthy();});


