import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server:ViteDevServer;let url:string;
test.setTimeout(60000);
test.use({viewport:{width:1440,height:900},launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{}});
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E8-16T/vite.config.ts',server:{host:'127.0.0.1',port:0}});await server.listen();url=new URL('/tests/Browser/E8-16T/fixture.html',server.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await server?.close();});
test('loading and retry show current data',async({page})=>{
 await page.route('**/subscription',route=>route.fulfill({json:{subscribed:false,expires_at:null}}));
 await page.goto(url+'?delay=1');await expect(page.getByRole('status').filter({hasText:'Загрузка хода замера'})).toBeAttached();
 await mkdir('.local/screenshots/SP-E8-16T',{recursive:true});await page.screenshot({path:'.local/screenshots/SP-E8-16T/loading.png',fullPage:true});
 await expect(page.getByRole('heading',{name:'События замера'})).toBeVisible();await expect(page.getByText(/1 из 2 слотов/)).toBeVisible();
 await page.goto(url+'?fail=1');await expect(page.getByRole('alert')).toContainText('Не удалось загрузить ход замера');
 await page.getByRole('button',{name:'Повторить',exact:true}).click();await expect(page.getByText(/1 из 2 слотов/)).toBeVisible();
});
test('completion uses actual clarification count and link',async({page})=>{
 for (const [theme,width] of [['neutral',1440],['confectionery',1024],['pyro',390]] as const) {
  await page.setViewportSize({width,height:900});await page.goto(url+'?complete=1&count=2&theme='+theme);
  await expect(page.locator('html')).toHaveAttribute('data-theme',theme);await expect(page.getByRole('status')).toContainText('Ожидают уточнения: 2');
  await expect(page.getByRole('link',{name:'Проверить уточнения'})).toHaveAttribute('href',/\/vision\/runs\/.*\/clarify\/mentions$/);
  await expect(page.getByRole('switch')).toHaveCount(0);expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1)).toBe(true);
  if(width===1440)await page.screenshot({path:'.local/screenshots/SP-E8-16T/complete.png',fullPage:true});
 }
 await page.goto(url+'?complete=1&unknown=1');await expect(page.getByRole('status')).toContainText('Количество уточнений пока недоступно.');await expect(page.getByRole('link',{name:'Проверить уточнения'})).toHaveCount(0);
 await page.goto(url+'?complete=1&count=0');await expect(page.getByRole('status')).toContainText('Ожидают уточнения: 0');await expect(page.getByRole('link',{name:'Проверить уточнения'})).toHaveCount(0);
 await page.goto(url+'?complete=1&count=0&empty=1');await expect(page.getByText('Слоты замера пока не созданы.')).toBeVisible();await expect(page.getByRole('heading',{name:'События замера'})).toBeVisible();
});
