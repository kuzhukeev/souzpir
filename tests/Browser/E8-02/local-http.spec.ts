import { test, expect } from '@playwright/test';

const base = process.env.E802_LOCAL_BASE ?? 'http://souzpir.local:8248';

test.use({
  launchOptions: {
    executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH,
    args: ['--host-resolver-rules=MAP souzpir.local 127.0.0.1'],
  },
});

test('local HTTP questionnaire works without browser Web Crypto', async ({ page, context }) => {
  page.on('pageerror', (error) => console.error('Browser error:', error.message));
  await context.route('**/*', (route) =>
    new URL(route.request().url()).origin === base ? route.continue() : route.abort(),
  );
  await page.goto(`${base}/login`);
  await page.locator('input[type=email]').fill('vision-e802@example.test');
  await page.locator('input[type=password]').fill('test-only-password-123');
  await page.getByRole('button', { name: 'Войти', exact: true }).click();
  await expect(page).not.toHaveURL(/\/login$/);
  await page.goto(`${base}/vision`);
  expect(await page.evaluate(() => !!window.crypto?.subtle)).toBe(false);
  await expect(
    page.getByText('Локальный режим: история анкеты в этом браузере не шифруется.'),
  ).toBeVisible();
  await page.getByRole('button', { name: 'Начать анкету' }).click();
  await expect(page).toHaveURL(/\/vision\/quiz\/1$/);
  await page.getByLabel('Название бренда', { exact: true }).fill('Проверка локального HTTP');
  await page.getByRole('button', { name: 'Сохранить и продолжить' }).click();
  await expect(page).toHaveURL(/\/vision\/quiz\/2$/);
  await page.goto(`${base}/vision/quiz/1`);
  await expect(page.getByLabel('Название бренда', { exact: true })).toHaveValue(
    'Проверка локального HTTP',
  );
  await page.screenshot({ path: '.local/screenshots/e8-02/local-http.png', fullPage: true });
});
