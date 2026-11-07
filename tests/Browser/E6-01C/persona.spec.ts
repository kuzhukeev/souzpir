import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';

let vite: ViteDevServer;
const fixture = 'http://127.0.0.1:5303/tests/Browser/E6-01C/fixture.html';

test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { vite = await createServer({ configFile: 'tests/Browser/E6-01C/vite.config.ts' }); await vite.listen(); });
test.afterAll(async () => { await vite?.close(); });

test('изменённая служебная фраза и быстрый ответ появляются в fake-примере', async ({ page }) => {
  await page.goto(fixture);
  await page.getByRole('tab', { name: 'Служебные фразы' }).click();
  await expect(page.getByText('Стоп-слов нет').first()).toBeVisible();
  await page.getByLabel('При ошибке').fill('Новая фраза для клиента.');
  await page.getByRole('button', { name: 'Проверить ответ' }).click();
  await expect(page.getByRole('status')).toHaveText('Ответ: Новая фраза для клиента.');
  await page.getByLabel('Сценарий пробного ответа').selectOption('quick:0');
  await page.getByRole('button', { name: 'Проверить ответ' }).click();
  await expect(page.getByRole('status')).toHaveText('Ответ: Спасибо за вопрос');
  await page.screenshot({ path: '.local/screenshots/e6-01c/phrases.png', fullPage: true });
});
