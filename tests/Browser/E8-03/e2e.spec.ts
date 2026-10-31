import { expect, test, type BrowserContext, type Page } from '@playwright/test';
const base = process.env.E803_BROWSER_BASE ?? 'http://souzpir.local:8258';
test.use({
  launchOptions: {
    executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe',
    args: ['--host-resolver-rules=MAP souzpir.local 127.0.0.1'],
  },
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
    const rejected = await page.evaluate(async () => {
      const response = await fetch('/vision/questions/candidates', {
        method: 'POST', credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
        body: JSON.stringify({ text: 'Как узнать размер?', intent: 'choose' }),
      });
      return response.status;
    });
    expect(rejected).toBe(419);
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

    // A cached private DOM must be hidden before BFCache and refetched after restoration.
    await page.goto(base + '/vision/questions');
    const marker = page.getByText('Как выбрать торт на свадьбу?');
    await expect(marker).toBeVisible();
    await page.evaluate(() => window.dispatchEvent(new PageTransitionEvent('pagehide', { persisted: true })));
    await expect(marker).toBeHidden();
    const freshData = page.waitForResponse((response) => response.url().endsWith('/vision/questions/data'));
    await page.evaluate(() => window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true })));
    await freshData;
    await expect(marker).toBeVisible();

    // Change the active brand through the real session endpoint, then navigate back.
    const switched = await page.evaluate(async (id) => {
      const token = decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))!.slice(11));
      const response = await fetch('/brand/switch', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': token },
        body: JSON.stringify({ brand_id: id }),
      });
      return response.ok;
    }, otherBrand);
    expect(switched).toBe(true);
    await page.goto(base + '/vision/questions');
    await expect(marker).toHaveCount(0);
    const afterSwitchLoad = page.waitForResponse((response) => response.url().endsWith('/vision/questions/data'));
    await page.goBack();
    await afterSwitchLoad;
    await expect(marker).toHaveCount(0);

    // Revoke the same session in another tab while this history entry is away.
    const logoutTab = await ctx.newPage();
    await logoutTab.goto(base + '/');
    const loggedOut = await logoutTab.evaluate(async () => {
      const token = decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))!.slice(11));
      return (await fetch('/logout', { method: 'POST', credentials: 'same-origin', headers: { 'X-XSRF-TOKEN': token } })).ok;
    });
    expect(loggedOut).toBe(true);
    await page.goBack();
    await expect(marker).toHaveCount(0);
    await expect(page).toHaveURL(/\/login/);
  } finally {
    await ctx.close();
    await view.close();
  }
});
