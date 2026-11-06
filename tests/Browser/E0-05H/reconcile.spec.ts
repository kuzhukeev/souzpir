import { test, expect } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
test.setTimeout(60000);
test.use({launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? {executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH} : {}});
let server: ViteDevServer;
let url: string;
test.beforeAll(async () => {
  server=await createServer({configFile:'tests/Browser/E0-05H/reconcile-vite.config.ts',server:{host:'127.0.0.1',port:0,strictPort:false}});
  await server.listen();
  url=new URL('/tests/Browser/E0-05H/reconcile-fixture.html',server.resolvedUrls!.local[0]).href;
});
test.afterAll(async () => { await server?.close(); });
test('подтверждение, ошибка и успешная сверка', async ({page}) => {
  const id='01ARZ3NDEKTSV4RRFFQ69G5FAV'; let posts=0; let completed=false;
  await page.route('**/ai/recovery/calls**', async route => {
    if(route.request().method()==='POST') {
      posts++;
      if(posts===1) return route.fulfill({status:503,contentType:'application/json',body:'{}'});
      completed=true;
      return route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({status:'completed'})});
    }
    return route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({calls:completed?[]:[{id,status:'uncertain',family:'seller',createdAt:'2026-11-01T12:00:00Z'}],nextCursor:null})});
  });
  await page.setViewportSize({width:1440,height:900});
  await page.goto(url);
  await page.getByRole('button',{name:'Сверить с провайдером'}).click();
  await expect(page.getByRole('alertdialog')).toBeVisible();
  expect(posts).toBe(0);
  await page.screenshot({path:'.local/runtime/e0-05h-confirm.png',fullPage:true});
  await page.getByRole('button',{name:'Подтвердить сверку'}).click();
  await expect(page.getByRole('alert')).toContainText('Не удалось получить результат');
  await page.getByRole('button',{name:'Подтвердить сверку'}).click();
  await expect(page.getByText(/Фактическая стоимость подтверждена/)).toBeVisible();
  await expect(page.getByText('Вызовов с неподтверждённым результатом нет.')).toBeVisible();
  await expect(page.getByText(/USD|costMicros|reservedMicros/)).toHaveCount(0);
  await page.screenshot({path:'.local/runtime/e0-05h-result.png',fullPage:true});
  expect(posts).toBe(2);
});
