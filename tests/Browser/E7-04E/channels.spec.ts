import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';

let server: ViteDevServer;
let fixture: string;
test.setTimeout(60000);
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => {
  server = await createServer({ configFile: 'tests/Browser/E7-04/vite.config.ts', server: { host: '127.0.0.1', port: 0, strictPort: false } });
  await server.listen();
  fixture = new URL('/tests/Browser/E7-04E/channels.html', server.resolvedUrls!.local[0]).href;
});
test.afterAll(async () => { await server?.close(); });

test('каналы показывают оценку, конверсию и пустые страницы сайта', async ({ page }) => {
  await page.goto(fixture);
  await page.getByRole('button',{name:'Источники и каналы',exact:true}).click();
  const section = page.getByRole('region', { name: 'Сводка продаж' });
  await expect(section.getByRole('row', { name: /Сайт 2 1 50% 4.2/ })).toBeVisible();
  await expect(section.getByRole('row').filter({ hasText: 'WhatsApp' }).getByText('Скоро')).toBeVisible();
  await expect(section.getByText('Данных по страницам пока нет: события открытия виджета и адрес страницы не сохраняются.')).toBeVisible();
  await section.scrollIntoViewIfNeeded();
  await page.screenshot({ path: '.local/screenshots/SP-E7-04E/channels.png' });
});
