import { test, expect } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
test.setTimeout(60000);
let server: ViteDevServer;
let url: string;
test.beforeAll(async () => {
  server = await createServer({configFile:'tests/Browser/E5-04D/vite.config.ts',server:{host:'127.0.0.1',port:0,strictPort:false}});
  await server.listen();
  url = new URL('/tests/Browser/E5-04D/fixture.html', server.resolvedUrls!.local[0]).href;
});
test.afterAll(async () => { await server?.close(); });
test('счётчики и сортировка моих диалогов', async ({ page }) => {
  await page.setViewportSize({width:1440,height:900});
  await page.goto(url);
  await expect(page.getByLabel('Счётчики моих диалогов')).toContainText('Ждут ответа 1');
  await expect(page.getByLabel('Счётчики моих диалогов')).toContainText('В работе 2');
  await expect(page.getByLabel('Счётчики моих диалогов')).toContainText('Закрыты сегодня 1');
  await expect(page.getByLabel('Сортировка')).toHaveValue('newest');
  await expect(page.getByText('Ответ менеджера и подсказки появятся после подключения отправки в SP-E3-03B.')).toHaveCount(0);
  await page.screenshot({path:'.local/runtime/e5-04d-mine.png',fullPage:true});
  await page.setViewportSize({width:390,height:900});
  await expect(page.getByLabel('Счётчики моих диалогов')).toBeVisible();
});
