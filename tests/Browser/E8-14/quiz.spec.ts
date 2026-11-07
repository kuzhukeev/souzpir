import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';

let vite: ViteDevServer;
const base = 'http://127.0.0.1:5298/tests/Browser/E8-14/fixture.html';
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { vite = await createServer({ configFile: 'tests/Browser/E8-14/vite.config.ts' }); await vite.listen(); });
test.afterAll(async () => { await vite?.close(); });

test('первый запуск и проверка сайта', async ({ page }) => {
  await page.goto(base);
  await expect(page.getByRole('heading', { name: 'Что проверяем' })).toBeVisible();
  await expect(page.getByText('ChatGPT')).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/e8-14/start.png', fullPage: true });
  await page.goto(base + '?screen=quiz');
  await expect(page.getByText(/Сайт доступен/)).toBeVisible();
});

test('подсказки из аудита добавляются на четыре шага', async ({ page }) => {
  await page.goto(base + '?screen=hints');
  await page.getByRole('button', { name: /Фейерверки/ }).click();
  await expect(page.getByRole('listitem').filter({ hasText: 'Фейерверки' })).toBeVisible();
  await page.goto(base + '?screen=competitors');
  await page.getByRole('button', { name: /Добавить из аудита/ }).click();
  await expect(page.getByRole('textbox', { name: 'Название' })).toHaveValue('Магазин Искра');
  await page.goto(base + '?screen=facts');
  await page.getByRole('button', { name: /Добавить факт/ }).click();
  await expect(page.getByLabel('С сайта')).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/e8-14/facts.png', fullPage: true });
  await page.goto(base + '?screen=questions');
  await page.getByRole('button', { name: /Как выбрать салют/ }).click();
  await expect(page.getByRole('listitem').filter({ hasText: 'Как выбрать салют?' })).toBeVisible();
});

test('отправка, повтор ошибки и переход к вопросам', async ({ page }) => {
  await page.goto(base + '?screen=review');
  await expect(page.getByRole('list').filter({ hasText: 'Сохраним профиль бренда' })).toContainText('После настройки можно запустить замер.');
  await page.route('**/vision/quiz/submit', route => route.fulfill({ status: 422, contentType: 'application/json', body: JSON.stringify({ message: 'Проверьте ответы' }) }));
  await page.getByRole('button', { name: 'Отправить анкету →' }).click();
  await expect(page.getByRole('button', { name: 'Повторить' })).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/e8-14/review.png', fullPage: true });
  await page.goto(base + '?screen=profile&submitted');
  await expect(page.getByRole('link', { name: 'Перейти к вопросам' })).toBeVisible();
});
