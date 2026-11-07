import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer; let url: string;
test.use({viewport:{width:1440,height:900},launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? {executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH} : {}});
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E8-24L/vite.config.ts',server:{host:'127.0.0.1',port:0}});await server.listen();url=new URL('/tests/Browser/E8-24L/fixture.html',server.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await server?.close();});
const member={id:'u',name:'Иван Петров',role:'owner',canReadAnswers:true,cabinet:true,email:false};
test('loading, rescue and retry load the recipients',async({page})=>{
 let count=0; let release!:()=>void; const pending=new Promise<void>(resolve=>{release=resolve;});
 await page.route('**/tests/Browser/E8-24L/fixture.html',async route=>{
  if(!route.request().headers()['x-inertia']) return route.continue();
  count++; if(count===1) await pending;
  const props=count===1?{}:{members:[member]};
  return route.fulfill({status:200,headers:{'X-Inertia':'true'},json:{component:'Fixture',props,...(count===1?{rescuedProps:['members']}:{}),url:new URL(url).pathname,version:'fixture'}});
 });
 await page.goto(url); await page.getByRole('button',{name:'Получатели',exact:true}).click();
 await expect(page.getByRole('status').filter({hasText:'Загружаем получателей'})).toBeAttached();
 await mkdir('.local/screenshots/SP-E8-24L',{recursive:true}); await page.screenshot({path:'.local/screenshots/SP-E8-24L/loading.png',fullPage:true});
 release(); await expect(page.getByRole('alert')).toContainText('Не удалось загрузить получателей.');
 await page.screenshot({path:'.local/screenshots/SP-E8-24L/error.png',fullPage:true});
 await page.getByRole('button',{name:'Повторить',exact:true}).click();
 await expect(page.getByLabel('Иван Петров: email')).toBeVisible(); expect(count).toBe(2);
});
test('network failure can be retried',async({page})=>{
 let count=0;
 await page.route('**/tests/Browser/E8-24L/fixture.html',async route=>{
  if(!route.request().headers()['x-inertia']) return route.continue(); count++;
  if(count===1)return route.abort();
  return route.fulfill({status:200,headers:{'X-Inertia':'true'},json:{component:'Fixture',props:{members:[member]},url:new URL(url).pathname,version:'fixture'}});
 });
 await page.goto(url); await page.getByRole('button',{name:'Получатели',exact:true}).click();
 await expect(page.getByRole('alert')).toContainText('Не удалось загрузить получателей.');
 await page.getByRole('button',{name:'Повторить',exact:true}).click();
 await expect(page.getByLabel('Иван Петров: email')).toBeVisible(); expect(count).toBe(2);
});
