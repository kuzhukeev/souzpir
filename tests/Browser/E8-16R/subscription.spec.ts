import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server:ViteDevServer; let url:string;
test.setTimeout(60000);
test.use({viewport:{width:1440,height:900},launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? {executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH} : {}});
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E8-16R/vite.config.ts',server:{host:'127.0.0.1',port:0,strictPort:false}});await server.listen();url=new URL('/tests/Browser/E8-16R/fixture.html',server.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await server?.close();});
test('персональная подписка включается и показывает ошибку',async({page})=>{
 await page.route('**/subscription',route=>route.request().method()==='GET' ? route.fulfill({json:{subscribed:false,expires_at:null}}) : route.fulfill({json:{subscribed:true,expires_at:'2099-01-01T00:00:00Z'}}));
 await page.goto(url);
 const control=page.getByRole('switch',{name:'Уведомить о готовности: выключено'});
 await expect(control).toBeEnabled(); await control.click();
 await expect(page.getByRole('switch',{name:'Уведомить о готовности: включено'})).toHaveAttribute('aria-checked','true');
 await expect(page.getByText(/Подписка действует до/)).toBeVisible();
 await mkdir('.local/screenshots/SP-E8-16R',{recursive:true}); await page.screenshot({path:'.local/screenshots/SP-E8-16R/progress.png',fullPage:true});
 await page.unroute('**/subscription'); await page.route('**/subscription',route=>route.abort());
 await page.getByRole('switch',{name:'Уведомить о готовности: включено'}).click();
 await expect(page.getByRole('alert')).toContainText('Не удалось сохранить подписку');
});

test('управление доступно в трёх темах и на узких экранах',async({page})=>{
 await page.route('**/subscription',route=>route.fulfill({json:{subscribed:false,expires_at:null}}));
 for (const [theme,width] of [['neutral',1440],['confectionery',1024],['pyro',390]] as const) {
  await page.setViewportSize({width,height:900}); await page.goto(url+'?theme='+theme);
  await expect(page.locator('html')).toHaveAttribute('data-theme',theme);
  await expect(page.getByRole('switch',{name:'Уведомить о готовности: выключено'})).toBeEnabled();
  expect(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth)).toBe(true);
 }
});
