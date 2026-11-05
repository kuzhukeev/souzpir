import { test, expect } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';

let server: ViteDevServer;
let url: string;
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => {
  server = await createServer({ configFile: 'tests/Browser/E7-02M/vite.config.ts', server: { host: '127.0.0.1', port: 0, strictPort: false } });
  await server.listen();
  url = new URL('/tests/Browser/E7-02M/fixture.html', server.resolvedUrls!.local[0]).href;
});
test.afterAll(async () => { await server?.close(); });
test('показывает только проверенные правила без числовой оценки',async({page})=>{await page.goto(url);await page.getByRole('button',{name:'Открыть'}).click();await expect(page.getByText('Ход 1 · Без HTML: выполнено')).toBeVisible();await expect(page.getByText('Ход 1 · Не более одного вопроса: нарушено')).toBeVisible();await expect(page.getByText('unconfirmed_rule')).toHaveCount(0);await expect(page.getByText('Это проверки правил, а не числовая оценка качества диалога.')).toBeVisible();await mkdir('.local/screenshots/sp-e7-02m',{recursive:true});await page.screenshot({path:'.local/screenshots/sp-e7-02m/checks.png',fullPage:true});});
test('выбор черновика и отправка прогона', async ({ page }) => {
  await page.goto(url);
  await page.getByLabel('Новый покупатель').check();
  await page.getByLabel('Версия знаний').selectOption('draft');
  await mkdir('.local/screenshots/sp-e7-02m', { recursive: true });
  await page.screenshot({ path: '.local/screenshots/sp-e7-02m/draft.png', fullPage: true });
  let payload: unknown = null;
  await page.route('**/seller/quality/runs', async route => { payload = route.request().postDataJSON(); await route.abort(); });
  await page.getByRole('button', { name: 'Запустить', exact: true }).click();
  await expect.poll(() => payload).toEqual({ scenarios: ['one'], knowledge: 'draft', model: 'fake' });
});
