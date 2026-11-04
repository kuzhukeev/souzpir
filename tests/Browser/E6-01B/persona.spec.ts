import { expect, test } from '@playwright/test';
import { spawn, type ChildProcess } from 'node:child_process';

let server: ChildProcess;
const fixture = 'http://127.0.0.1:5296/tests/Browser/E6-01B/fixture.html';
test.beforeAll(async () => {
  server = spawn(process.execPath, ['node_modules/vite/bin/vite.js', '--config', 'tests/Browser/E6-01B/vite.config.ts'], { stdio: 'ignore' });
  for (let attempt = 0; attempt < 60; attempt++) {
    try { if ((await fetch(fixture)).ok) return; } catch { /* Wait for Vite. */ }
    if (server.exitCode !== null) throw new Error('Vite fixture exited before startup');
    await new Promise(resolve => setTimeout(resolve, 500));
  }
  throw new Error('Vite fixture did not start');
});
test.afterAll(() => { server?.kill(); });
test.use({ launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });

test('персонаж и служебные фразы', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(fixture);
  await expect(page.getByLabel('Должность')).toHaveValue('Консультант');
  await expect(page.getByText('Живой пример')).toBeVisible();
  await page.getByRole('button', { name: 'Обновить пример' }).click();
  await expect(page.getByText('Расскажите, что вас интересует')).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/e6-01b/persona-1440.png', fullPage: true });
  await page.getByRole('tab', { name: 'Служебные фразы' }).click();
  await expect(page.getByLabel('При ошибке')).toHaveValue('Секунду, уточню пару моментов и вернусь к вам сегодня.');
  await expect(page.getByRole('textbox', { name: 'Шаблон 1' })).toHaveValue('Спасибо за вопрос');
  await page.screenshot({ path: '.local/screenshots/e6-01b/phrases-1440.png', fullPage: true });
  await page.setViewportSize({ width: 390, height: 844 });
  await expect(page.getByRole('button', { name: 'Сохранить фразы' })).toBeVisible();
});
