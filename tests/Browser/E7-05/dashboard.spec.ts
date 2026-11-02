import { expect, test } from '@playwright/test';
import { spawn, type ChildProcess } from 'node:child_process';

let server: ChildProcess;
const fixture = 'http://127.0.0.1:5285/tests/Browser/E7-05/fixture.html';
test.beforeAll(async () => {
  server = spawn(process.execPath, ['node_modules/vite/bin/vite.js', '--config', 'tests/Browser/E7-05/vite.config.ts'], { stdio: 'ignore' });
  for (let attempt = 0; attempt < 60; attempt++) {
    try { if ((await fetch(fixture)).ok) return; } catch { /* Wait for Vite. */ }
    if (server.exitCode !== null) throw new Error('Vite fixture exited before startup');
    await new Promise(resolve => setTimeout(resolve, 500));
  }
  throw new Error('Vite fixture did not start');
});
test.afterAll(() => { server?.kill(); });
test.use({ launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });

test('сводка, события и пустое состояние', async ({ page }) => {
  for (const width of [1440, 1024, 390]) {
    await page.setViewportSize({ width, height: 900 });
    await page.goto(fixture);
    await expect(page.getByRole('heading', { name: 'Сводка продавца' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Главные проблемы' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Топ тем' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Загрузка менеджеров' })).toBeVisible();
    await expect(page.getByText('248', { exact: true }).first()).toBeVisible();
    const size = await page.evaluate(() => ({ scroll: document.documentElement.scrollWidth, viewport: innerWidth, offenders: [...document.querySelectorAll('main,main *')].filter(el => el.getBoundingClientRect().right > innerWidth + 1).slice(0, 8).map(el => `${el.tagName}.${String(el.className).slice(0, 45)}`) }));
    expect(size.scroll, `overflow at ${width}px: ${JSON.stringify(size)}`).toBeLessThanOrEqual(size.viewport);
    if (width === 1440) await page.screenshot({ path: '.local/screenshots/sp-e7-05b/summary-1440.png', fullPage: true });
  }
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(`${fixture}?attention`);
  await expect(page.getByRole('heading', { name: 'Требует внимания' })).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/sp-e7-05/attention-1440.png', fullPage: true });
  await page.getByPlaceholder('Найти событие').fill('несуществующий');
  await expect(page.getByText('Событий по выбранному фильтру нет.')).toBeVisible();
  await page.goto(`${fixture}?empty`);
  await expect(page.getByRole('heading', { name: 'Пока нет диалогов' })).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/sp-e7-05/empty-1440.png', fullPage: true });
});

test('показывает загрузку и повтор при ошибке обновления', async ({ page }) => {
  await page.route('**/seller?**', async route => {
    await new Promise(resolve => setTimeout(resolve, 500));
    await route.fulfill({ status: 503, body: 'Unavailable' });
  });
  await page.goto(fixture);
  await page.getByRole('button', { name: 'Показать' }).click();
  await expect(page.getByRole('status')).toContainText('Загружаем');
  await expect(page.getByRole('alert')).toContainText('Не удалось загрузить сводку');
  await expect(page.getByRole('button', { name: 'Повторить' })).toBeVisible();
});
