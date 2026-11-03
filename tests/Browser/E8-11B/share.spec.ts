import { expect, test } from '@playwright/test';
import { spawn, type ChildProcess } from 'node:child_process';
let server: ChildProcess;
const fixture = 'http://127.0.0.1:5293/tests/Browser/E8-11B/fixture.html';
test.beforeAll(async () => {
  server = spawn(process.execPath, ['node_modules/vite/bin/vite.js', '--config', 'tests/Browser/E8-11B/vite.config.ts'], { stdio: 'ignore' });
  for (let attempt = 0; attempt < 60; attempt++) {
    try { if ((await fetch(fixture)).ok) return; } catch { /* Wait for Vite. */ }
    if (server.exitCode !== null) throw new Error('Vite fixture exited before startup');
    await new Promise(resolve => setTimeout(resolve, 500));
  }
  throw new Error('Vite fixture did not start');
});
test.afterAll(() => { server?.kill(); });
test.use({ launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test('методика и создание публичной ссылки из отчёта', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(fixture);
  await expect(page.getByRole('button', { name:'Поделиться отчётом' })).toBeVisible();
  await page.getByText('Как рассчитаны показатели').click();
  await expect(page.getByText('Метрики сохранены в неизменяемом снимке отчёта.', { exact:false })).toBeVisible();
  await page.getByRole('button', { name:'Поделиться отчётом' }).click();
  await expect(page.getByRole('dialog')).toBeVisible();
  await expect(page.getByLabel('Срок действия')).toHaveValue('7');
  await expect(page.getByRole('checkbox', { name:'Разрешить PDF' })).toBeDisabled();
  await expect(page.getByRole('button', { name:'Создать ссылку' })).toBeEnabled();
});
