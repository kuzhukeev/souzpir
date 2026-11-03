import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';

let vite: ViteDevServer;
const base = 'http://127.0.0.1:5302/tests/Browser/E8-23/fixture.html';
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { vite = await createServer({ configFile: 'tests/Browser/E8-23/vite.config.ts' }); await vite.listen(); });
test.afterAll(async () => { await vite?.close(); });

test('по замерам видна линия доли голоса и выполненное действие', async ({ page }) => {
  await page.goto(base);
  await expect(page.getByRole('img', { name: 'Видимость и доля голоса по отчётам' })).toBeVisible();
  await expect(page.getByText('Добавлен новый каталог')).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/e8-23/measurements.png', fullPage: true });
});

test('намерения показаны стековыми столбцами и тепловой таблицей', async ({ page }) => {
  await page.goto(base);
  await page.getByRole('button', { name: 'По намерениям' }).click();
  await expect(page.getByLabel('Стековые столбцы намерений')).toBeVisible();
  await expect(page.getByRole('cell', { name: '48 %' })).toHaveAttribute('style', /color-mix/);
  await page.screenshot({ path: '.local/screenshots/e8-23/intents.png', fullPage: true });
});

test('доля голоса выделяет «Вы» и изменение в пунктах', async ({ page }) => {
  await page.goto(base);
  await page.getByRole('button', { name: 'Доля голоса' }).click();
  await expect(page.getByRole('img', { name: 'Доля голоса по компаниям' })).toBeVisible();
  await expect(page.getByRole('columnheader', { name: 'Изменение п.п.' })).toBeVisible();
  await expect(page.getByRole('row', { name: /Вы/ })).toContainText('+9 п.п.');
  await page.screenshot({ path: '.local/screenshots/e8-23/share.png', fullPage: true });
});

test('пустой и ошибочный ответы объясняют состояние', async ({ page }) => {
  await page.goto(`${base}?empty`);
  await expect(page.getByText('Пока нет данных. Подтвердите замер и подготовьте отчёт.')).toBeVisible();
  await page.goto(`${base}?error`);
  await expect(page.getByRole('alert')).toContainText('Не удалось загрузить отчёты.');
  await expect(page.getByRole('button', { name: 'Повторить' })).toBeVisible();
});
