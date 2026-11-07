import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';

let vite: ViteDevServer;
const base = 'http://127.0.0.1:5301/tests/Browser/E1-12D/fixture.html';
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { vite = await createServer({ configFile: 'tests/Browser/E1-12D/vite.config.ts' }); await vite.listen(); });
test.afterAll(async () => { await vite?.close(); });

test('сохранённые сигналы и инциденты показаны', async ({ page }) => {
  await page.goto(base);
  await expect(page.locator('[data-component-id="widget"]')).toContainText('Виджет');
  await expect(page.locator('[data-component-id="vision"]')).toContainText('По сохранённым событиям');
  await expect(page.getByText('Ошибка замера Видения')).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/e1-12d/status.png', fullPage: true });
});

test('без сигналов остаётся неизвестное состояние и пустая история', async ({ page }) => {
  await page.goto(base + '?empty');
  await expect(page.locator('[data-component-id="widget"]')).toContainText('Мониторинг недоступен');
  await expect(page.getByText('История инцидентов пока недоступна')).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/e1-12d/status-empty.png', fullPage: true });
});
