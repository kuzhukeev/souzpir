import { expect, test } from '@playwright/test';
import { spawn, type ChildProcess } from 'node:child_process';
import { mkdir } from 'node:fs/promises';
let server: ChildProcess;
const url='http://127.0.0.1:5299/tests/Browser/E4-08/knowledge-fixture.html';
test.beforeAll(async()=>{server=spawn(process.execPath,['node_modules/vite/bin/vite.js','--config','tests/Browser/E4-08/knowledge-vite.config.ts'],{stdio:'ignore'});for(let i=0;i<60;i++){try{if((await fetch(url)).ok)return;}catch{/* Ожидаем сервер. */}await new Promise(resolve=>setTimeout(resolve,500));}throw new Error('Vite не запустился');});
test.afterAll(()=>server?.kill());
test.use({launchOptions:process.env.E409_CHROMIUM_PATH?{executablePath:process.env.E409_CHROMIUM_PATH}:{}});
test('редактор эскалаций и предпросмотр YAML',async({page})=>{
 await page.setViewportSize({width:1440,height:900});await page.goto(url);await expect(page.getByRole('heading',{name:'Причины передачи менеджеру'})).toBeVisible();await expect(page.getByLabel('Название')).toHaveValue('Вопрос о доставке');
 await mkdir('.local/screenshots/sp-e4-09',{recursive:true});await page.screenshot({path:'.local/screenshots/sp-e4-09/escalation-1440.png',fullPage:true});
 await page.goto(`${url}?imports`);await expect(page.getByRole('heading',{name:'Импорт базы знаний из YAML'})).toBeVisible();
 await page.getByLabel('Файлы YAML или ZIP').setInputFiles({name:'knowledge.zip',mimeType:'application/zip',buffer:Buffer.from('fixture')});
 await page.route('**/seller/knowledge/imports/yaml/preview',route=>route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({sections:{facts:2,intents:3}})}));
 await page.getByRole('button',{name:'Предпросмотр YAML'}).click();await expect(page.getByText('Факты: 2')).toBeVisible();await expect(page.getByRole('button',{name:'Опубликовать базу знаний'})).toBeVisible();
 await page.screenshot({path:'.local/screenshots/sp-e4-09/import-1440.png',fullPage:true});
});


