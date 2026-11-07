import {expect,test} from '@playwright/test';
import {createServer,type ViteDevServer} from 'vite';
import {mkdir} from 'node:fs/promises';
let server:ViteDevServer;let url:string;
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E7-01K/vite.config.ts',cacheDir:'.local/runtime/vite-e701k',server:{host:'127.0.0.1',port:0}});await server.listen();url=new URL('/tests/Browser/E7-01K/fixture.html',server.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await server.close();});
test.use({launchOptions:{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}});
test('пустая оценка, закрытие и недоступная кнопка',async({page})=>{await page.setViewportSize({width:1440,height:900});await page.goto(url);await page.getByRole('button',{name:'Оценка диалога'}).click();await expect(page.getByRole('dialog')).toBeVisible();await expect(page.getByText('Оценки пока нет.')).toBeVisible();await expect(page.getByRole('button',{name:'Оценить сейчас'})).toBeDisabled();await mkdir('.local/screenshots/SP-E7-01K',{recursive:true});await page.screenshot({path:'.local/screenshots/SP-E7-01K/empty.png'});await page.keyboard.press('Escape');await expect(page.getByRole('dialog')).toHaveCount(0);});
