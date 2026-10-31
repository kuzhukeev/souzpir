import { expect, test, type BrowserContext, type Page } from '@playwright/test';
const base = 'http://127.0.0.1:8258';
test.use({
  launchOptions: { executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe' },
});
async function guard(context: BrowserContext) {
  await context.route('**/*', (r) =>
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
test('actual questions create, select, publish, version, permissions and CSRF', async ({
  browser,
}) => {
  test.setTimeout(180000);
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } }),
    view = await browser.newContext();
  await guard(ctx);
  await guard(view);
  const page = await ctx.newPage(),
    viewer = await view.newPage();
  page.setDefaultTimeout(15000);
  try {
    await login(page, 'vision-e803@example.test');
    await login(viewer, 'vision-e803-viewer@example.test');
    await page.goto(base + '/vision/questions');
    await expect(page.getByRole('heading', { level: 1 })).toContainText('Вопросы');
    await expect(page.getByText('Пока нет вопросов в этом разделе.')).toBeVisible();
    await page.getByRole('button', { name: 'Добавить из анкеты' }).click();
    await page.getByRole('dialog').getByRole('button', { name: 'Добавить', exact: true }).click();
    await expect(page.getByRole('status')).toContainText('Добавлено: 0');
    await page.getByRole('button', { name: 'Добавить свой' }).click();
    await page.getByLabel('Текст вопроса').fill('Как выбрать торт на свадьбу?');
    await page.getByRole('button', { name: 'Добавить вопрос', exact: true }).click();
    await expect(page.getByText('Как выбрать торт на свадьбу?')).toBeVisible();
    await page.getByRole('button', { name: 'В набор' }).click();
    await expect(page.getByText('Выбран', { exact: true })).toBeVisible();
    await page.getByRole('link', { name: 'Перейти к утверждению' }).click();
    await expect(page.getByRole('heading', { name: 'Выбрано 1 вопросов' })).toBeVisible();
    await page.getByLabel('google/gemini-2.5-flash').check();
    await expect(page.getByLabel('yandexgpt')).toBeDisabled();
    await page.getByRole('button', { name: 'Утвердить без запуска' }).click();
    await expect(page).toHaveURL(/\/vision\/questions\/active$/);
    await expect(page.getByText('Версия 1 · Активный')).toBeVisible();
    await expect(page.getByText('Как выбрать торт на свадьбу?')).toBeVisible();
    await page.screenshot({ path: '.local/screenshots/e8-03/real-active.png', fullPage: true });
    await viewer.goto(base + '/vision/questions/active');
    await expect(viewer.getByText('Как выбрать торт на свадьбу?')).toBeVisible();
    await expect(viewer.getByRole('button', { name: 'Изменить набор' })).toHaveCount(0);
    await page.getByRole('button', { name: 'Изменить набор' }).click();
    await expect(page).toHaveURL(/\/vision\/questions$/);
    await page.getByRole('button', { name: 'Добавить свой' }).click();
    await page.getByLabel('Текст вопроса').fill('Как узнать цену доставки?');
    await page.getByLabel('Цена и бюджет').check();
    await page.getByRole('button', { name: 'Добавить вопрос', exact: true }).click();
    await page.getByRole('button', { name: 'В набор', exact: true }).click();
    await page.getByRole('link', { name: 'Перейти к утверждению' }).click();
    await page.getByLabel('google/gemini-2.5-flash').check();
    await page.getByRole('button', { name: 'Утвердить без запуска' }).click();
    await expect(page.getByText('Версия 2 · Активный')).toBeVisible();
    await page.getByRole('link', { name: 'История наборов' }).click();
    await expect(page.getByRole('heading', { name: 'Версия 2 · Активный' })).toBeVisible();
    await page.getByRole('button', { name: 'Сравнить с предыдущим' }).click();
    await expect(page.getByText('Как узнать цену доставки?')).toBeVisible();
    await page.screenshot({ path: '.local/screenshots/e8-03/real-history.png', fullPage: true });
    const rejected = await ctx.request.post(base + '/vision/questions/candidates', {
      headers: { Accept: 'application/json', 'Sec-Fetch-Site': 'cross-site' },
      data: { text: 'Как узнать размер?', intent: 'choose' },
    });
    expect(rejected.status()).toBe(419);
    const otherBrand = await page.evaluate(() => {
      const node = document.querySelector('script[data-page="app"]');
      if (!node?.textContent) return null;
      const body = JSON.parse(node.textContent);
      return body.props.brands.find(
        (brand: { id: string; name: string }) => brand.name === 'Зета · другой бренд',
      )?.id as string | undefined;
    });
    expect(otherBrand).toBeTruthy();
    if (!otherBrand) throw new Error('Fixture brand is missing.');
    const foreign = await viewer.evaluate(async (id) => {
      const response = await fetch('/vision/questions/data', {
        headers: { Accept: 'application/json', 'X-Brand-Id': id },
      });
      return { status: response.status, text: await response.text() };
    }, otherBrand);
    expect(foreign.status).toBeGreaterThanOrEqual(400);
    expect(foreign.text).not.toContain('Как выбрать торт на свадьбу?');
  } finally {
    await ctx.close();
    await view.close();
  }
});
