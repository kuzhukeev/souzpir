import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer; let url: string;
test.use({launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? {executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH} : {}});
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E1-03E/vite.config.ts',server:{host:'127.0.0.1',port:0}});await server.listen();url=new URL('/tests/Browser/E1-03E/fixture.html',server.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await server?.close();});
test('декоративные товары в трёх темах и ширинах',async({page})=>{
 for(const theme of ['neutral','pyro','confectionery']) for(const width of [1440,1024,390]) {
  await page.setViewportSize({width,height:900}); await page.goto(`${url}?dashboard&theme=${theme}`);
  const image=page.locator('img[src="/images/dashboard-products.svg"]');
  await expect(image).toBeVisible(); await expect(image).toHaveAttribute('alt','');
  await expect(image.locator('..')).toHaveAttribute('aria-hidden','true');
  expect(await image.evaluate((el: HTMLImageElement)=>el.complete && el.naturalWidth > 0)).toBeTruthy();
  expect(await page.evaluate(()=>document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
  if(theme==='neutral' && width===1440){await mkdir('.local/screenshots/SP-E1-03E',{recursive:true});await page.screenshot({path:'.local/screenshots/SP-E1-03E/dashboard.png',fullPage:true});}
 }
});
