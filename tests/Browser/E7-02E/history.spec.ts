import {test,expect} from '@playwright/test';
import {createServer,type ViteDevServer} from 'vite';
import {mkdir} from 'node:fs/promises';
let server:ViteDevServer;let url:string;
test.use({viewport:{width:1440,height:900},launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{}});
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E7-02E/vite.config.ts',cacheDir:'.local/runtime/vite-e702e',server:{host:'127.0.0.1',port:0,strictPort:false}});await server.listen();url=new URL('/tests/Browser/E7-02E/fixture.html',server.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await server?.close();});
test('история периодически обновляется и сообщает об ошибке',async({page})=>{await page.clock.install();await page.goto(url);await expect(page.getByText('История обновляется автоматически.')).toBeVisible();await mkdir('.local/screenshots/SP-E7-02E',{recursive:true});await page.screenshot({path:'.local/screenshots/SP-E7-02E/history.png',fullPage:true});let called=false;await page.route('**/seller/quality/runs',async route=>{called=true;await route.abort();});await page.clock.fastForward(16000);await expect.poll(()=>called).toBe(true);await expect(page.getByRole('alert')).toContainText('Не удалось обновить историю прогонов.');});
