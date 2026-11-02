import { expect, test } from '@playwright/test';
import { spawn, type ChildProcess } from 'node:child_process';

let server: ChildProcess;
const fixture = 'http://127.0.0.1:5275/tests/Browser/E4-04/fixture.html';

test.beforeAll(async () => {
  server = spawn(process.execPath, ['node_modules/vite/bin/vite.js', '--config', 'tests/Browser/E4-04/vite.config.ts'], { stdio: 'ignore' });
  for (let attempt = 0; attempt < 60; attempt++) {
    try { if ((await fetch(fixture)).ok) return; } catch { /* Wait for Vite. */ }
    if (server.exitCode !== null) throw new Error('Vite fixture exited before startup');
    await new Promise(resolve => setTimeout(resolve, 500));
  }
  throw new Error('Vite fixture did not start');
});

test.afterAll(() => { server?.kill(); });

test.use({ launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });

test('список тем и редактор черновика', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(fixture);
  await expect(page.getByRole('heading', { name: 'Темы вопросов' })).toBeVisible();
  await expect(page.getByText('ask_delivery')).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/sp-e4-04/topics-1440.png', fullPage: true });
  await page.getByRole('button', { name: 'Редактировать' }).first().click();
  const dialog = page.getByRole('dialog');
  await expect(dialog.getByLabel('Описание для бота *')).toHaveValue('Когда клиент спрашивает о доставке и сроках получения заказа.');
  await expect(dialog.getByLabel('Текст вопроса-мостика')).toHaveValue('В какой город нужна доставка?');
  await page.screenshot({ path: '.local/screenshots/sp-e4-04/topic-editor-1440.png', fullPage: true });
  await dialog.getByRole('button', { name: 'Отмена' }).click();
  await expect(dialog).toHaveCount(0);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
});
