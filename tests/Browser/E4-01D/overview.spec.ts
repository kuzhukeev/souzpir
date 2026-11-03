import { expect, test } from '@playwright/test';
import { spawn, type ChildProcess } from 'node:child_process';
let server: ChildProcess;
const fixture='http://127.0.0.1:5295/tests/Browser/E4-01D/fixture.html';
test.beforeAll(async()=>{server=spawn(process.execPath,['node_modules/vite/bin/vite.js','--config','tests/Browser/E4-01D/vite.config.ts'],{stdio:'ignore'});for(let i=0;i<60;i++){try{if((await fetch(fixture)).ok)return;}catch{/* Wait for Vite. */}if(server.exitCode!==null)throw new Error('Vite exited');await new Promise(resolve=>setTimeout(resolve,500));}throw new Error('Vite did not start');});
test.afterAll(()=>server?.kill());
test.use({launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{}});
test('обзор и фильтрация входящих',async({page})=>{
 await page.setViewportSize({width:1440,height:900});await page.goto(fixture);
 await expect(page.getByText('Темы вопросов',{exact:true})).toBeVisible();
 await expect(page.getByText('Изменений с публикации: 2')).toBeVisible();
 await expect(page.getByRole('link',{name:'Проверить в песочнице →'})).toHaveAttribute('href','/seller/knowledge/sandbox');
 await expect(page.getByText('Сколько доставка? · 2')).toBeVisible();
 await expect(page.getByText('Встречался 2 раз',{exact:false})).toHaveCount(2);
 await expect(page.getByRole('link',{name:'Открыть диалог'}).first()).toHaveAttribute('href','/seller/dialogs/dialog1');
 await page.getByLabel('Сортировка вопросов').selectOption('frequent');
 await expect(page.getByLabel('Сортировка вопросов')).toHaveValue('frequent');
 await page.screenshot({path:'.local/screenshots/e4-01d/overview.png',fullPage:true});
 await page.getByLabel('Источник').selectOption('review');
 await expect(page.getByText('Проверить цену')).toBeVisible();
 await expect(page.getByText('Неизвестный вопрос',{exact:true})).toHaveCount(0);
 await page.screenshot({path:'.local/screenshots/e4-01d/incoming.png',fullPage:true});
});
test('сравнение показывает значения без JSON-разметки',async({page})=>{
 await page.setViewportSize({width:1440,height:900});await page.goto(`${fixture}?compare`);
 await expect(page.getByText('text: Срок — 5 дней')).toBeVisible();
 await expect(page.getByText('text: Срок — 3 дня')).toBeVisible();
 await page.screenshot({path:'.local/screenshots/e4-01d/compare.png',fullPage:true});
});
