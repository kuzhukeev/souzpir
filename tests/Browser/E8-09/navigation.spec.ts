import { expect, test } from '@playwright/test';
import { spawn, type ChildProcess } from 'node:child_process';
import { mkdir } from 'node:fs/promises';
let server:ChildProcess;
const url='http://127.0.0.1:5304/tests/Browser/E8-09/fixture.html';
test.beforeAll(async()=>{server=spawn(process.execPath,['node_modules/vite/bin/vite.js','--config','tests/Browser/E8-09/vite.config.ts'],{stdio:'ignore'});for(let i=0;i<60;i++){try{if((await fetch(url)).ok)return;}catch{/* Ожидаем сервер. */}await new Promise(resolve=>setTimeout(resolve,500));}throw new Error('Vite не запустился');});
test.afterAll(()=>server?.kill());
test.use({launchOptions:process.env.E809_CHROMIUM_PATH?{executablePath:process.env.E809_CHROMIUM_PATH}:{}});
for(const width of [390,1024,1440])test(`доступность меню при ширине ${width}`,async({page})=>{
 await page.setViewportSize({width,height:900});await page.goto(url);
 if(width===390)await page.getByRole('button',{name:'Открыть меню'}).click();
 let nav=page.getByRole('navigation',{name:'Основная навигация'}).filter({visible:true});
 await nav.getByRole('button',{name:'Видение'}).click();
 await expect(nav.getByTitle('Отчёт — Сначала заполните анкету')).toHaveAttribute('aria-disabled','true');
 await expect(nav.getByRole('link',{name:'Анкета'})).toHaveAttribute('href','/vision');
 if(width===1440){await mkdir('.local/screenshots/sp-e8-09',{recursive:true});await page.screenshot({path:'.local/screenshots/sp-e8-09/locked-1440.png',fullPage:true});}
 await page.goto(`${url}?ready`);if(width===390)await page.getByRole('button',{name:'Открыть меню'}).click();nav=page.getByRole('navigation',{name:'Основная навигация'}).filter({visible:true});await nav.getByRole('button',{name:'Видение'}).click();
 for(const [name,href] of [['Вопросы','/vision/questions'],['Замеры','/vision/runs'],['Конкуренты','/vision/competitors'],['Источники','/vision/sources'],['Сайт','/vision/site'],['Действия','/vision/actions'],['Динамика','/vision/trends']] as const)await expect(nav.getByRole('link',{name,exact:true})).toHaveAttribute('href',href);
 const report=nav.getByRole('link',{name:'Отчёт',exact:true});await expect(report).toHaveAttribute('href','/vision/report');await expect(nav.getByRole('link',{name:'Профиль бренда'})).toHaveAttribute('href','/vision/profile');await expect(nav.getByRole('link',{name:'Публикации'})).toHaveAttribute('href','/vision/publications');await report.focus();await expect(report).toBeFocused();
 if(width===1440)await page.screenshot({path:'.local/screenshots/sp-e8-09/unlocked-1440.png',fullPage:true});
});
test('права скрывают настройки без разрешения',async({page})=>{await page.goto(`${url}?viewer&ready`);const nav=page.getByRole('navigation',{name:'Основная навигация'}).filter({visible:true});await nav.getByRole('button',{name:'Видение'}).click();await expect(nav.getByText('Настройки',{exact:true})).toHaveCount(0);});



test('роль без права редактирования видит блокировки до анкеты',async({page})=>{await page.goto(`${url}?viewer`);const nav=page.getByRole('navigation',{name:'Основная навигация'}).filter({visible:true});await nav.getByRole('button',{name:'Видение'}).click();await expect(nav.getByTitle('Отчёт — Сначала заполните анкету')).toHaveAttribute('aria-disabled','true');});
