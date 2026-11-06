import {expect,test} from '@playwright/test';
import {createServer,type ViteDevServer} from 'vite';
import {mkdir} from 'node:fs/promises';
let server:ViteDevServer;let url:string;
test.setTimeout(60000);
test.use({viewport:{width:1440,height:900},launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{}});
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E1-08E/vite.config.ts',server:{host:'127.0.0.1',port:0}});await server.listen();url=new URL('/tests/Browser/E1-08E/profile.html',server.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await server?.close();});
test('сохранение событий доступно только после изменения',async({page})=>{
 await page.goto(url+'?screen=notifications&active');
 const save=page.getByRole('button',{name:'Сохранить изменения'});await expect(save).toBeDisabled();
 const sw=page.getByRole('switch',{name:/Диалог ждёт менеджера: В кабинете/});await sw.click();await expect(save).toBeEnabled();await sw.click();await expect(save).toBeDisabled();
 const timing=page.getByRole('button',{name:'Сохранить настройки'});await expect(timing).toBeDisabled();
 await page.getByLabel('Сводка вместо каждого события: раз в день').check();await expect(timing).toBeEnabled();
 await expect(page.getByText('Тестовая доставка')).toBeVisible();
 await mkdir('.local/screenshots/SP-E1-08E',{recursive:true});await page.screenshot({path:'.local/screenshots/SP-E1-08E/notifications.png',fullPage:true});
});
test('в профиле доступен диалог смены адреса при разрешённом флаге',async({page})=>{
 await page.goto(url);const button=page.getByRole('button',{name:'Изменить почту'});await expect(button).toBeEnabled();await button.click();await expect(page.getByRole('dialog')).toBeVisible();
 await mkdir('.local/screenshots/SP-E1-08E',{recursive:true});await page.screenshot({path:'.local/screenshots/SP-E1-08E/profile.png',fullPage:true});
});
