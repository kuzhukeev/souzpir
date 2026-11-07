import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let vite:ViteDevServer;let url:string;
test.use({viewport:{width:1440,height:900},launchOptions:process.env.E603I_CHROMIUM_PATH?{executablePath:process.env.E603I_CHROMIUM_PATH}:{}});
test.beforeAll(async()=>{vite=await createServer({configFile:'tests/Browser/E6-03I/vite.config.ts',server:{host:'127.0.0.1',port:0,strictPort:false}});await vite.listen();url=new URL('/tests/Browser/E6-03I/fixture.html',vite.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await vite?.close();});
test('показываются сообщения после тестовой отправки',async({page})=>{await page.goto(url);await expect(page.getByText('Сообщения после тестовой отправки',{exact:true})).toBeVisible();await expect(page.getByText(/причинная связь не подтверждена/)).toBeVisible();await mkdir('.local/screenshots/sp-e6-03i',{recursive:true});await page.screenshot({path:'.local/screenshots/sp-e6-03i/followups.png',fullPage:true});});
