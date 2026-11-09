import { expect, test } from '@playwright/test';
import { spawn, type ChildProcess } from 'node:child_process';
import { mkdir } from 'node:fs/promises';
import { createServer } from 'node:net';

let server: ChildProcess | undefined;
let base = '';
test.beforeAll(async () => {
  test.setTimeout(60000);
  const port = await new Promise<number>((resolve, reject) => {
    const socket = createServer();
    socket.on('error', reject);
    socket.listen(0, '127.0.0.1', () => {
      const address = socket.address();
      if (!address || typeof address === 'string') return reject(new Error('No fixture port'));
      socket.close(error => error ? reject(error) : resolve(address.port));
    });
  });
  base = `http://127.0.0.1:${port}/tests/Browser/E8-10/fixture.html`;
  await mkdir('.local/screenshots/sp-e8-10', { recursive: true });
  server = spawn(process.execPath, ['node_modules/vite/bin/vite.js', '--config', 'tests/Browser/E8-10/vite.config.ts', '--host', '127.0.0.1', '--port', String(port), '--strictPort'], { stdio: 'ignore', windowsHide: true });
  for (let attempt = 0; attempt < 60; attempt++) {
    if (server.exitCode !== null) throw new Error('Fixture server exited before readiness');
    try { if ((await fetch(base)).ok) return; } catch { /* Wait for Vite. */ }
    await new Promise(resolve => setTimeout(resolve, 500));
  }
  server.kill();
  throw new Error('Fixture server did not become ready');
});
test.afterAll(() => server?.kill());
test.use({ launchOptions: { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH }, viewport: { width: 1440, height: 900 } });

test('four wizard screens and typed controls', async ({ page }) => {
  await page.goto(`${base}?step=mentions`);
  await expect(page.getByRole('heading', { name: 'Это про вас?' })).toBeVisible();
  await page.getByRole('radio', { name: 'Да, это мы' }).check();
  await expect(page.getByText('Отметить для добавления в профиль')).toHaveCount(0);
  await page.screenshot({ path: '.local/screenshots/sp-e8-10/mentions-1440.png', fullPage: true });
  await page.goto(`${base}?step=presence`);
  await expect(page.getByRole('heading', { name: 'Вы есть на этих площадках?' })).toBeVisible();
  await expect(page.getByRole('textbox', { name: 'Ссылка на профиль (если есть)' })).toBeVisible();
  await page.goto(`${base}?step=facts`);
  await page.getByRole('radio', { name: 'Неверно' }).check();
  await expect(page.getByRole('textbox', { name: 'Как правильно' })).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/sp-e8-10/facts-1440.png', fullPage: true });
  await page.goto(`${base}?step=done`);
  await expect(page.getByText('Всего уточнений: 3. Требуют ответа или оставлены на потом: 1.')).toBeVisible();
  await expect(page.getByRole('link', { name: 'Открыть отчёт' })).toHaveAttribute('href', '/vision/report');
  await page.screenshot({ path: '.local/screenshots/sp-e8-10/done-1440.png', fullPage: true });
});

test('empty and unavailable states', async ({ page }) => {
  await page.goto(`${base}?step=presence&empty`);
  await expect(page.getByText('Нет площадок для уточнения.')).toBeVisible();
  await page.goto(`${base}?step=facts&unavailable`);
  await expect(page.getByText('Уточнения станут доступны после подтверждения завершённого замера.')).toBeVisible();
});
