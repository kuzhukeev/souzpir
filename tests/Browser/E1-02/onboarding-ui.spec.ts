import { expect, test } from '@playwright/test';
const url = (process.env.ONBOARDING_FIXTURE_URL || 'http://127.0.0.1:5183/tests/Browser/E1-02/ui-fixture.html');
test.use({ launchOptions: process.env.ONBOARDING_CHROMIUM_PATH ? { executablePath: process.env.ONBOARDING_CHROMIUM_PATH } : {} });
for (const width of [1440, 1024, 390]) {
  test(`company form fits ${width}`, async ({ page }) => {
    await page.setViewportSize({ width, height: 900 }); await page.goto(url);
    await expect(page.getByRole('heading', { name: 'Расскажите о компании' })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
    expect(await page.locator('body').evaluate((node) => parseFloat(getComputedStyle(node).fontSize))).toBeGreaterThanOrEqual(13);
    await page.screenshot({ path: `.local/screenshots/e1-02/company-${width}.png`, fullPage: true });
  });
}
test('validates company before advancing and handles a network failure', async ({ page }) => {
  await page.goto(url); await page.getByLabel('Название компании').fill(''); await page.getByRole('button', { name: 'Сохранить и продолжить' }).click();
  await expect(page.getByRole('alert')).toContainText('Укажите название компании');
  await page.getByLabel('Название компании').fill('Союз Пир');
  await page.route('**/start/draft', (route) => route.abort());
  await page.getByRole('button', { name: 'Сохранить и продолжить' }).click();
  await expect(page.getByRole('alert')).toContainText('Не удалось связаться с сервером');
  await expect(page.getByRole('button', { name: 'Сохранить и продолжить' })).toBeEnabled();
});
test('existing brand is not offered another first-brand form', async ({ page }) => {
  await page.goto(url + '?configured=1');
  await expect(page.getByText('Первичная настройка недоступна')).toBeVisible();
  await expect(page.getByLabel('Название бренда')).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Завершить настройку' })).toHaveCount(0);
});
test('brand theme cards and accessible markup', async ({ page }) => {
  await page.goto(url + '?step=2'); await expect(page.getByRole('heading', { name: 'Сайт и бренд' })).toBeVisible();
  await expect(page.getByRole('radio', { name: /Пиротехника/ })).toBeChecked();
  await page.getByRole('radio', { name: /Кондитерская/ }).check();
  await expect(page.getByRole('radio', { name: /Кондитерская/ })).toBeChecked();
  await page.addScriptTag({ path: 'node_modules/axe-core/axe.min.js' });
  const violations = await page.evaluate(async () => (await (window as unknown as { axe: { run: (root: Document, options: object) => Promise<{ violations: { id: string }[] }> } }).axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa'] } })).violations);
  expect(violations).toEqual([]);
});


test('draft is sent to server on navigation and next step is shown only after success', async ({ page }) => {
  await page.goto(url);
  const initialPage = await page.evaluate(() => JSON.parse(document.querySelector('script[data-page="app"]')!.textContent!));
  let payload: Record<string, unknown> = {};
  await page.route('**/start/draft', async (route) => {
    payload = route.request().postDataJSON();
    initialPage.props.initial = { ...payload, completed: false, already_configured: false };
    await route.fulfill({ status: 200, contentType: 'application/json', headers: { 'x-inertia': 'true' }, body: JSON.stringify(initialPage) });
  });
  await page.getByRole('button', { name: 'Сохранить и продолжить' }).click();
  await expect(page.getByRole('heading', { name: 'Сайт и бренд' })).toBeVisible();
  expect(payload.step).toBe(2); expect(payload.organization_name).toBe('Союз Пир');
  await expect(page.getByRole('status')).toContainText('Черновик сохранён');
});

test('general server error is visible instead of a fake success', async ({ page }) => {
  await page.goto(url);
  const initialPage = await page.evaluate(() => JSON.parse(document.querySelector('script[data-page="app"]')!.textContent!));
  await page.route('**/start/draft', (route) => {
    initialPage.props.errors = { onboarding: 'Первичная настройка уже завершена.' };
    return route.fulfill({ status: 200, contentType: 'application/json', headers: { 'x-inertia': 'true' }, body: JSON.stringify(initialPage) });
  });
  await page.getByRole('button', { name: 'Сохранить и продолжить' }).click();
  await expect(page.getByRole('alert')).toContainText('Первичная настройка уже завершена.');
  await expect(page.getByRole('heading', { name: 'Расскажите о компании' })).toBeVisible();
});


for (const step of [2, 3, 4]) {
  test(`onboarding step ${step} is accessible and legible`, async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 }); await page.goto(url + '?step=' + step);
    const smallText = await page.locator('body').evaluate((body) => Array.from(body.querySelectorAll('*')).filter((node) => {
      const style = getComputedStyle(node); const rect = node.getBoundingClientRect();
      return Array.from(node.childNodes).some((child) => child.nodeType === Node.TEXT_NODE && child.textContent?.trim()) && style.visibility !== 'hidden' && style.display !== 'none' && rect.width > 0 && rect.height > 0 && parseFloat(style.fontSize) < 13;
    }).map((node) => node.outerHTML.slice(0, 120)));
    expect(smallText).toEqual([]);
    await page.addScriptTag({ path: 'node_modules/axe-core/axe.min.js' });
    const violations = await page.evaluate(async () => (await (window as unknown as { axe: { run: (root: Document, options: object) => Promise<{ violations: { id: string }[] }> } }).axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa'] } })).violations);
    expect(violations).toEqual([]);
    await page.screenshot({ path: `.local/screenshots/e1-02/step-${step}-1440.png`, fullPage: true });
  });
}


test('logo selection rejects unsupported SVG before upload', async ({ page }) => {
  await page.goto(url + '?step=2');
  let uploads = 0;
  page.on('request', (request) => { if (request.method() === 'POST' && request.url().includes('/start/logo')) uploads++; });
  await page.getByLabel('Логотип', { exact: true }).setInputFiles({ name: 'unsafe.svg', mimeType: 'image/svg+xml', buffer: Buffer.from('<svg/>') });
  await expect(page.getByRole('alert')).toContainText('SVG пока не поддерживается');
  expect(uploads).toBe(0);
});


test('completed or existing cabinet has a usable fallback', async ({ page }) => {
  await page.goto(url + '?completed=1');
  await expect(page.getByRole('link', { name: 'Перейти в кабинет' })).toHaveAttribute('href', '/auth/ready');
  await page.goto(url + '?configured=1');
  await expect(page.getByRole('link', { name: 'Перейти в кабинет' })).toHaveAttribute('href', '/auth/ready');
});
test('module links require real entitlement in addition to routes', async ({ page }) => {
  await page.goto(url + '?completed=1&routes=1');
  await expect(page.getByRole('link', { name: /Начать анкету|Открыть базу знаний/ })).toHaveCount(0);
  await page.goto(url + '?completed=1&routes=1&enabled=1');
  await expect(page.getByRole('link', { name: /Начать анкету/ })).toHaveAttribute('href', '/vision/quiz/1');
  await expect(page.getByRole('link', { name: /Открыть базу знаний/ })).toHaveAttribute('href', '/seller/knowledge');
});
