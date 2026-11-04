import {expect,test} from '@playwright/test';
import {createServer,type ViteDevServer} from 'vite';
import {mkdir} from 'node:fs/promises';
let server:ViteDevServer;let url:string;
test.use({viewport:{width:1440,height:900},launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{}});
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E4-01K/vite.config.ts',server:{host:'127.0.0.1',port:0}});await server.listen();url=new URL('/tests/Browser/E4-01K/fixture.html',server.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await server?.close();});
test('выбор файла для восстановления',async({page})=>{await page.goto(url);const input=page.getByLabel('Восстановить копию из JSON');const button=page.getByRole('button',{name:'Восстановить из файла'});await expect(button).toBeDisabled();await input.setInputFiles({name:'backup.json',mimeType:'application/json',buffer:Buffer.from('{}')});await expect(button).toBeEnabled();await input.scrollIntoViewIfNeeded();await mkdir('.local/screenshots/sp-e4-01k',{recursive:true});await page.screenshot({path:'.local/screenshots/sp-e4-01k/restore.png',fullPage:true});});
