import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
let server: ViteDevServer;
let fixture: string;
test.setTimeout(60000);
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { server = await createServer({ configFile: 'tests/Browser/E6-02C/vite.config.ts' }); await server.listen(); fixture = new URL('/tests/Browser/E6-02C/fixture.html', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });

test('site sections, variables and after hours preview', async ({ page }) => {
  await page.goto(fixture);
  await expect(page.getByText('Здравствуйте, Анна! Раздел: Главная')).toBeVisible();
  await page.getByLabel('Раздел сайта').selectOption('catalog');
  await expect(page.getByText('Рады видеть в каталоге, Анна.')).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/SP-E6-02C/greetings.png', fullPage: true });
  await page.getByRole('checkbox', { name: 'В нерабочее время' }).check();
  await expect(page.getByText('Вернёмся завтра, Анна.')).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/SP-E6-02C/after-hours.png', fullPage: true });
  await expect(page.getByRole('option', { name: 'WhatsApp · Скоро' })).toHaveAttribute('disabled', '');
});

test('viewer can inspect but not change greeting', async ({ page }) => {
  await page.goto(`${fixture}?readonly`);
  await expect(page.getByLabel('Текст приветствия')).toBeDisabled();
  await expect(page.getByRole('button', { name: 'Сохранить' })).toHaveCount(0);
});
