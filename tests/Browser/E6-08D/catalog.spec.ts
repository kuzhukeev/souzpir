import { test, expect } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
test.setTimeout(60000);
let server: ViteDevServer;
let url: string;
test.beforeAll(async () => {
  server = await createServer({configFile:'tests/Browser/E6-08D/vite.config.ts',server:{host:'127.0.0.1',port:0,strictPort:false}});
  await server.listen();
  url = new URL('/tests/Browser/E6-08D/fixture.html', server.resolvedUrls!.local[0]).href;
});
test.afterAll(async () => { await server?.close(); });
test('источники каталога, цены и все колонки', async ({ page }) => {
  await page.setViewportSize({width:1440,height:900});
  await page.goto(url);
  await expect(page.getByRole('button',{name:'Google Sheets · Скоро'})).toBeDisabled();
  await expect(page.getByRole('checkbox',{name:'Бот может называть цены · Скоро'})).toBeDisabled();
  await expect(page.getByText('Отправка файла ботом будет подключена позже.')).toBeVisible();
  for(const header of ['Артикул','Название','Категория','Цена опт','Цена розница','Наличие','Обновлено']) await expect(page.getByRole('columnheader',{name:header,exact:true})).toBeVisible();
  await expect(page.getByRole('cell',{name:'Есть',exact:true})).toBeVisible();
  await page.screenshot({path:'.local/runtime/e6-08d-catalog.png',fullPage:true});
});
test('источники остатков и пример ответа', async ({ page }) => {
  await page.setViewportSize({width:1440,height:900});
  await page.goto(url+'?stock');
  await expect(page.getByText('Каталог ✓')).toBeVisible();
  await expect(page.getByRole('button',{name:'Внешний URL · Скоро'})).toBeDisabled();
  await expect(page.getByRole('button',{name:'1С · Скоро'})).toBeDisabled();
  await expect(page.getByText('«Проверю наличие этого товара в каталоге.»')).toBeVisible();
  await expect(page.getByText('Мало · 4')).toBeVisible();
  await page.screenshot({path:'.local/runtime/e6-08d-stock.png',fullPage:true});
});

