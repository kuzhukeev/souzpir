import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer; let url: string;
test.use({viewport:{width:1440,height:900},launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{}});
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E5-04G/vite.config.ts',cacheDir:'.local/runtime/vite-e504p',server:{host:'127.0.0.1',port:0,strictPort:false}});await server.listen();url=new URL('/tests/Browser/E5-04P/fixture.html',server.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await server?.close();});
test('готовая подсказка помещается в ответ без отправки',async({page})=>{await page.goto(url);await expect(page.getByText('Проверенный вариант ответа')).toBeVisible();await page.getByRole('button',{name:'Использовать в ответе'}).click();await expect(page.getByRole('textbox',{name:'Сообщение',exact:true})).toHaveValue('Проверенный вариант ответа');await expect(page.getByRole('button',{name:'Отправить',exact:true})).toBeDisabled();await mkdir('.local/screenshots/SP-E5-04P',{recursive:true});await page.screenshot({path:'.local/screenshots/SP-E5-04P/suggestion.png',fullPage:true});});