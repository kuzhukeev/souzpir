import { expect, test } from '@playwright/test';
import { spawn, type ChildProcess } from 'node:child_process';
import { mkdir } from 'node:fs/promises';
test.use({launchOptions:process.env.E603H_CHROMIUM_PATH?{executablePath:process.env.E603H_CHROMIUM_PATH}:{}});
let server:ChildProcess;
const url='http://127.0.0.1:5381/tests/Browser/E6-03H/fixture.html';
test.beforeAll(async()=>{server=spawn(process.execPath,['node_modules/vite/bin/vite.js','--config','tests/Browser/E6-03H/vite.config.ts'],{stdio:'ignore'});for(let i=0;i<100;i++){try{if((await fetch(url)).ok)return;}catch{/* Ждём сервер. */}await new Promise(resolve=>setTimeout(resolve,500));}throw new Error('Vite не запустился');});
test.afterAll(()=>server?.kill());
test('таблица, пустое состояние и ошибка',async({page})=>{await page.setViewportSize({width:1440,height:900});await page.goto(url);await expect(page.getByRole('cell',{name:'Анна'})).toBeVisible();await expect(page.getByRole('button',{name:'Отменить'})).toBeVisible();await mkdir('.local/screenshots/sp-e6-03h',{recursive:true});await page.screenshot({path:'.local/screenshots/sp-e6-03h/followups.png',fullPage:true});await page.route('**/seller/followups/123/cancel',route=>route.abort());await page.getByRole('button',{name:'Отменить'}).click();await expect(page.getByRole('alert')).toContainText('Не удалось');await page.goto(url+'?empty');await expect(page.getByText('За выбранный период напоминаний нет.')).toBeVisible();});
