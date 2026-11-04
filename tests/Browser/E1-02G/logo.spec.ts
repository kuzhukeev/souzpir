import {expect,test} from '@playwright/test';
import {createServer,type ViteDevServer} from 'vite';
import {mkdir} from 'node:fs/promises';
let server:ViteDevServer;let url:string;
test.use({viewport:{width:1440,height:900},launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{}});
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E1-02G/vite.config.ts',server:{host:'127.0.0.1',port:0}});await server.listen();url=new URL('/tests/Browser/E1-02G/ui-fixture.html?tab=theme',server.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await server?.close();});
test('приватный SVG отображается в оформлении бренда',async({page})=>{await page.route('**/private-logo.svg',route=>route.fulfill({contentType:'image/svg+xml',body:'<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48"><circle cx="24" cy="24" r="20" fill="orange"/></svg>'}));await page.goto(url);const logo=page.getByRole('img',{name:'Логотип бренда'});await expect(logo).toBeVisible();await expect.poll(()=>logo.evaluate((img:HTMLImageElement)=>img.naturalWidth)).toBeGreaterThan(0);await mkdir('.local/screenshots/sp-e1-02g',{recursive:true});await page.screenshot({path:'.local/screenshots/sp-e1-02g/brand.png',fullPage:true});});
