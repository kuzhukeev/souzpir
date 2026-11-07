import { test, expect } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer; let url: string;
test.use({viewport:{width:1440,height:900},launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{}});
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E5-09C/vite.config.ts',server:{host:'127.0.0.1',port:0,strictPort:false}});await server.listen();url=new URL('/tests/Browser/E5-09C/fixture-05c.html?attention',server.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await server?.close();});
test('событие отключения и неизвестное время',async({page})=>{await page.goto(url);await expect(page.getByRole('table').getByText('Канал выключен >24ч')).toBeVisible();await expect(page.getByRole('table').getByText('1 · Время отключения неизвестно')).toBeVisible();await mkdir('.local/screenshots/SP-E5-09C',{recursive:true});await page.screenshot({path:'.local/screenshots/SP-E5-09C/attention.png',fullPage:true});});
