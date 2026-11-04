import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';

let server: ViteDevServer;
let url: string;
test.use({
  viewport: { width: 1440, height: 900 },
  launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH
    ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH }
    : {},
});
test.beforeAll(async () => {
  server = await createServer({
    configFile: 'tests/Browser/E8-20O/vite.config.ts',
    server: { host: '127.0.0.1', port: 0, strictPort: false },
  });
  await server.listen();
  url = new URL('/tests/Browser/E8-20O/fixture.html', server.resolvedUrls!.local[0]).href;
});
test.afterAll(async () => {
  await server?.close();
});

test('карточка, история и отслеживание компании', async ({ page }) => {
  await page.goto(url + '?edit=1');
  await expect(page.getByRole('button', { name: 'Отслеживать: Альфа ООО' })).toHaveText('☆');
  await mkdir('.local/screenshots/sp-e8-20o', { recursive: true });
  await page.screenshot({ path: '.local/screenshots/sp-e8-20o/companies.png', fullPage: true });
  const detail = page
    .locator('details')
    .filter({ has: page.locator('summary').filter({ hasText: 'Альфа ООО' }) })
    .first();
  await detail.locator('summary').click();
  await expect(detail.getByText('В вопросах:')).toContainText('2 из 4');
  await expect(detail.getByRole('figure', { name: /3 сопоставимым замерам/ })).toBeVisible();
  await expect(detail.getByText('Где купить?', { exact: false })).toBeVisible();
  await expect(detail.getByText('Подтверждённые цитаты пока недоступны.')).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/sp-e8-20o/company-card.png', fullPage: true });
});

test('дубли выбираются и подтверждаются, лидеры требуют общей базы', async ({ page }) => {
  await page.goto(url + '?edit=1');
  await page.getByRole('checkbox', { name: 'Дубль: Альфа ООО' }).check();
  await page.getByRole('checkbox', { name: 'Дубль: Гамма' }).check();
  await expect(page.getByLabel('Основное название', { exact: true })).toHaveValue('Гамма');
  await page.getByRole('button', { name: 'Объединить', exact: true }).last().click();
  await expect(page.getByRole('dialog', { name: 'Объединить названия?' })).toContainText('Гамма');
  await page.getByRole('button', { name: 'Отмена', exact: true }).click();
  await page.getByLabel('Первая компания').selectOption('Альфа');
  await page.getByLabel('Вторая компания').selectOption('Альфа ООО');
  const advantages = page.getByRole('region', { name: 'Преимущества компаний' });
  await expect(advantages.getByText('Выбор и сравнение: Альфа ООО · 80 %')).toBeVisible();
  await expect(advantages.getByText('Цена: Альфа · 60 %')).toBeVisible();
  await page.goto(url + '?incomplete=1');
  await page.getByLabel('Первая компания').selectOption('Альфа');
  await page.getByLabel('Вторая компания').selectOption('Альфа ООО');
  await expect(
    page
      .getByRole('region', { name: 'Преимущества компаний' })
      .getByText('Выбор и сравнение: Альфа ООО · 80 %'),
  ).toHaveCount(0);
  await expect(page.getByRole('checkbox', { name: 'Дубль: Альфа ООО' })).toHaveCount(0);
});
