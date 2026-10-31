import { test, expect, type BrowserContext, type Page } from '@playwright/test';
const base = 'http://127.0.0.1:8248';
test.use({
  launchOptions: { executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe' },
});
async function guard(c: BrowserContext) {
  await c.route('**/*', (r) =>
    new URL(r.request().url()).origin === base ? r.continue() : r.abort(),
  );
}
async function login(page: Page, email: string) {
  await page.goto(base + '/login');
  await page.locator('input[type=email]').fill(email);
  await page.locator('input[type=password]').fill('test-only-password-123');
  await page.getByRole('button', { name: 'Войти', exact: true }).click();
  await expect(page).not.toHaveURL(/\/login$/);
}
test('actual questionnaire persists and creates immutable profile versions', async ({
  browser,
}) => {
  test.setTimeout(180000);
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } }),
    other = await browser.newContext();
  await guard(ctx);
  await guard(other);
  const page = await ctx.newPage(),
    stranger = await other.newPage();
  page.setDefaultTimeout(15000);
  try {
    await login(page, 'vision-e802@example.test');
    await login(stranger, 'vision-e802-other@example.test');
    await page.goto(base + '/vision');
    await page.getByRole('button', { name: 'Начать анкету' }).click();
    await expect(page).toHaveURL(/\/vision\/quiz\/1$/);
    const huge = 'х'.repeat(33000);
    await page.getByLabel('Юридическое имя').fill(huge);
    await page.getByRole('button', { name: 'Сохранить и продолжить' }).click();
    await expect(page.getByRole('alert')).toBeVisible();
    await expect(page.getByLabel('Юридическое имя')).toHaveValue(huge);
    await page.getByLabel('Юридическое имя').fill('');
    const race = await ctx.newPage();
    await race.goto(base + '/vision/quiz/1');
    await race.getByLabel('Сайт', { exact: true }).fill('https://example.com');
    await race.getByLabel('Название бренда', { exact: true }).fill('Версия в другой вкладке');
    await race.getByRole('button', { name: 'Сохранить и продолжить' }).click();
    await expect(race).toHaveURL(/\/vision\/quiz\/2$/);
    await page.getByLabel('Название бренда', { exact: true }).fill('Несохранённый ввод');
    await page.getByRole('button', { name: 'Сохранить и продолжить' }).click();
    await expect(page.getByRole('alert')).toBeVisible();
    await expect(page.getByLabel('Название бренда', { exact: true })).toHaveValue(
      'Несохранённый ввод',
    );
    await page.reload();
    await expect(page.getByLabel('Название бренда', { exact: true })).toHaveValue(
      'Версия в другой вкладке',
    );
    await race.close();
    await page.getByLabel('Сайт', { exact: true }).fill('https://example.com');
    await page.getByLabel('Название бренда', { exact: true }).fill('Проверочный бренд');
    await page.getByRole('button', { name: 'Сохранить и продолжить' }).click();
    await expect(page).toHaveURL(/\/vision\/quiz\/2$/);
    for (const value of ['example.com', 'Проверочный бренд']) {
      await page.getByLabel('Добавить: Официальные названия').fill(value);
      await page.getByRole('button', { name: 'Добавить в Официальные названия' }).click();
    }
    await page.getByRole('button', { name: 'Сохранить и продолжить' }).click();
    await expect(page).toHaveURL(/\/vision\/quiz\/3$/);
    await page.getByLabel('Добавить: Категории товаров и услуг').fill('Пиротехника');
    await page.getByRole('button', { name: 'Добавить в Категории товаров и услуг' }).click();
    await page.getByLabel('Товары', { exact: true }).check();
    await page.getByLabel('Оба', { exact: true }).check();
    await page
      .getByLabel('Как покупатель описывает потребность')
      .fill('купить салют для праздника');
    await page.getByLabel('Категория: именительный падеж').fill('салюты');
    await page.getByLabel('Категория: родительный падеж, множественное число').fill('салютов');
    for (let step = 3; step <= 7; step++) {
      await page.getByRole('button', { name: 'Сохранить и продолжить' }).click();
      await expect(page).toHaveURL(new RegExp(`/vision/quiz/${step + 1}$`));
    }
    await page.screenshot({ path: '.local/screenshots/e8-02/real-review.png', fullPage: true });
    await page.getByRole('button', { name: 'Отправить анкету' }).click();
    await expect(page).toHaveURL(/\/vision\/profile$/);
    await expect(page.getByRole('heading', { name: 'Профиль бренда' })).toBeVisible();
    await expect(page.getByText('Проверочный бренд').first()).toBeVisible();
    await page.screenshot({ path: '.local/screenshots/e8-02/real-profile.png', fullPage: true });
    const firstProfile = await page.getByRole('link', { name: 'Версия 1' }).getAttribute('href');
    expect(firstProfile).toBeTruthy();
    await stranger.goto(base + firstProfile);
    await expect(stranger).not.toHaveURL(new RegExp(firstProfile! + '$'));
    await page.getByRole('button', { name: 'Изменить', exact: true }).first().click();
    await page.getByLabel('Название бренда', { exact: true }).fill('Изменённый бренд');
    await page.getByRole('button', { name: 'Сохранить', exact: true }).click();
    await expect(page.getByRole('link', { name: 'Версия 2', exact: true })).toBeVisible();
    await expect(page.getByText('Было', { exact: true }).first()).toBeVisible();
    await expect(page.getByText('Стало', { exact: true }).first()).toBeVisible();
    await page.screenshot({ path: '.local/screenshots/e8-02/real-history.png', fullPage: true });
  } finally {
    await ctx.close();
    await other.close();
  }
});

test('private GET without Web Crypto never exposes answers', async ({ browser }) => {
  const context = await browser.newContext();
  await guard(context);
  await context.addInitScript(() => {
    Object.defineProperty(crypto, 'subtle', { value: undefined, configurable: true });
  });
  const page = await context.newPage();
  try {
    await login(page, 'vision-e802@example.test');
    await page.goto(base + '/vision/profile');
    await expect(page.getByText(/Для защиты ответов нужен браузер с Web Crypto/)).toBeVisible();
    const body = await page.locator('body').innerText();
    expect(body).not.toContain('Изменённый бренд');
    expect(JSON.stringify(await page.evaluate(() => history.state))).not.toContain(
      'Изменённый бренд',
    );
  } finally {
    await context.close();
  }
});
