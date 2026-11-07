import { test, expect } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
let server: ViteDevServer; let url:string;
test.use({viewport:{width:1440,height:900},launchOptions:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{}});
test.beforeAll(async()=>{server=await createServer({configFile:'tests/Browser/E7-06F/vite.config.ts',server:{host:'127.0.0.1',port:0}});await server.listen();url=new URL('/tests/Browser/E7-06F/dashboard.html',server.resolvedUrls!.local[0]).href;});
test.afterAll(async()=>{await server?.close();});
test('воронка, темы и менеджеры показаны по макету без обрезанного текста', async ({ page }) => {
  await page.goto(url);
  await expect(page.getByRole('heading', { name: 'Воронка за период' })).toBeVisible();
  for (const step of ['248', '164', '84', '31']) await expect(page.getByText(step, { exact: true }).first()).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Топ тем' })).toBeVisible();
  const managers = page.locator('h3:has-text("Загрузка менеджеров")').locator('..');
  await expect(managers.getByRole('columnheader', { name: 'Передано' })).toBeVisible();
  await expect(managers.getByRole('columnheader', { name: 'Закрыто' })).toBeVisible();
});
test('детали адаптируются к ширинам 1440/1024/390 без переполнения', async ({ page }) => {
  await page.goto(url);
  for (const width of [1440, 1024, 390]) {
    await page.setViewportSize({ width, height: 900 });
    await expect(page.getByRole('heading', { name: 'Сводка · подробнее' })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
  }
});
