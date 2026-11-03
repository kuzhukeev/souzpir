import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let vite:ViteDevServer;
test.use({viewport:{width:1440,height:900},launchOptions:process.env.E824_CHROMIUM_PATH?{executablePath:process.env.E824_CHROMIUM_PATH}:{}});
test.beforeAll(async()=>{vite=await createServer({configFile:'tests/Browser/E8-24/vite.config.ts'});await vite.listen();});
test.afterAll(async()=>{await vite?.close();});
test('модели, ручной режим, уведомления и роль',async({page})=>{await page.goto('http://127.0.0.1:5397/tests/Browser/E8-24/fixture.html');await expect(page.getByText('Выбрано 1 из 2')).toBeVisible();await page.getByLabel('claude').check();await expect(page.getByText('Выбрано 2 из 2')).toBeVisible();await page.getByRole('button',{name:'Расписание'}).click();await page.getByLabel('Частота').selectOption('manual');await expect(page.getByLabel('День недели')).toHaveCount(0);await mkdir('.local/screenshots/sp-e8-24',{recursive:true});await page.screenshot({path:'.local/screenshots/sp-e8-24/schedule.png',fullPage:true});await page.getByRole('button',{name:'Уведомления',exact:true}).click();await expect(page.getByRole('link',{name:'Личные уведомления'})).toHaveAttribute('href','/profile/notifications');await expect(page.getByRole('link',{name:'Подключить каналы'})).toBeVisible();await page.getByRole('button',{name:'Получатели'}).click();await expect(page.getByRole('cell',{name:'Владелец'})).toBeVisible();await page.screenshot({path:'.local/screenshots/sp-e8-24/recipients.png',fullPage:true});});
