import { expect, test } from '@playwright/test';
import { spawn, type ChildProcess } from 'node:child_process';

let server: ChildProcess;
const fixture = 'http://127.0.0.1:5278/tests/Browser/E5-08/fixture.html';
test.beforeAll(async () => {
  server = spawn(process.execPath, ['node_modules/vite/bin/vite.js', '--config', 'tests/Browser/E5-08/vite.config.ts'], { stdio: 'ignore' });
  for (let attempt = 0; attempt < 60; attempt++) {
    try { if ((await fetch(fixture)).ok) return; } catch { /* Wait for Vite. */ }
    if (server.exitCode !== null) throw new Error('Vite fixture exited before startup');
    await new Promise(resolve => setTimeout(resolve, 500));
  }
  throw new Error('Vite fixture did not start');
});
test.afterAll(() => { server?.kill(); });
test.use({ launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });

test('совпадения, поиск и предпросмотр импорта', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(fixture);
  await expect(page.getByRole('heading', { name: 'Дубли клиентов' })).toBeVisible();
  await expect(page.getByText('Совпадает: Телефон')).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/sp-e5-08/duplicates-1440.png', fullPage: true });
  await page.getByLabel('Поиск по контактам').fill('несуществующий');
  await expect(page.getByText('По запросу совпадений нет.')).toBeVisible();
  await page.goto(`${fixture}?import`);
  await expect(page.getByRole('heading', { name: 'Импорт и экспорт клиентов' })).toBeVisible();
  await expect(page.getByText('Всего строк: 1.')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Импортировать 1 запись' })).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/sp-e5-08/import-1440.png', fullPage: true });
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
});
