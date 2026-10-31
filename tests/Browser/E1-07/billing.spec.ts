import { expect, test, type BrowserContext, type Page } from '@playwright/test';
import { mkdir, readFile } from 'node:fs/promises';

test.skip(
  process.env.RUN_BILLING_E2E !== '1',
  'Requires the isolated SP-E1-07 test database and server.',
);
type Fixture = { owner_email: string; brands: Record<string, { id: string; name: string }> };
let fixture: Fixture;
let ownerCookies: Awaited<ReturnType<BrowserContext['cookies']>>;

async function localOnly(context: BrowserContext) {
  await context.route('**/*', (route) =>
    new URL(route.request().url()).origin === 'http://127.0.0.1:8107'
      ? route.continue()
      : route.abort(),
  );
}

async function chooseTheme(page: Page, theme: string) {
  await page.goto('/billing');
  await expect(page.locator('main h1')).toBeVisible();
  if ((await page.locator('html').getAttribute('data-theme')) !== theme) {
    const status = await page.evaluate(async (brandId) => {
      const token = document.cookie.split('; ').find((item) => item.startsWith('XSRF-TOKEN='));
      if (!token) throw new Error('Missing test session CSRF cookie');
      const response = await fetch('/brand/switch', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-XSRF-TOKEN': decodeURIComponent(token.slice('XSRF-TOKEN='.length)),
        },
        body: JSON.stringify({ brand_id: brandId }),
      });
      return response.status;
    }, fixture.brands[theme].id);
    expect(status).toBe(200);
    await page.goto('/billing');
  }
  await expect(page.locator('html')).toHaveAttribute('data-theme', theme);
}

async function checkLayout(page: Page) {
  await page.evaluate(() => document.fonts.ready.then(() => true));
  expect(
    await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1),
  ).toBeTruthy();
  const smallText = await page.locator('body').evaluate((body) =>
    Array.from(body.querySelectorAll('*'))
      .filter((node) => {
        const style = getComputedStyle(node);
        const rect = node.getBoundingClientRect();
        return (
          Array.from(node.childNodes).some(
            (child) => child.nodeType === Node.TEXT_NODE && child.textContent?.trim(),
          ) &&
          style.visibility !== 'hidden' &&
          style.display !== 'none' &&
          rect.width > 0 &&
          rect.height > 0 &&
          parseFloat(style.fontSize) < 13
        );
      })
      .map((node) => `${node.tagName}:${getComputedStyle(node).fontSize}`),
  );
  expect(smallText).toEqual([]);
}

async function checkAccessibility(page: Page) {
  await page.addScriptTag({ path: 'node_modules/axe-core/axe.min.js' });
  const violations = await page.evaluate(async () => {
    const axe = (
      window as unknown as {
        axe: {
          run: (context: Document, options: object) => Promise<{ violations: { id: string }[] }>;
        };
      }
    ).axe;
    return (
      await axe.run(document, {
        runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21aa'] },
      })
    ).violations.map(({ id }) => id);
  });
  expect(violations).toEqual([]);
}

test.beforeAll(async ({ browser }) => {
  fixture = JSON.parse(await readFile('.local/runtime/e1-07-browser.json', 'utf8')) as Fixture;
  await mkdir('.local/screenshots/e1-07', { recursive: true });
  const context = await browser.newContext({ baseURL: 'http://127.0.0.1:8107' });
  await localOnly(context);
  const page = await context.newPage();
  await page.goto('/login');
  await page.getByLabel('Рабочая почта').fill(fixture.owner_email);
  await page.getByLabel('Пароль', { exact: true }).fill('test-only-password-123');
  await page.getByRole('button', { name: 'Войти', exact: true }).click();
  await expect(page).toHaveURL(/\/auth\/ready$/);
  ownerCookies = await context.cookies();
  await context.close();
});

test.beforeEach(async ({ context }) => {
  await localOnly(context);
  await context.addCookies(ownerCookies);
});

for (const width of [1440, 1024, 390]) {
  for (const theme of ['neutral', 'pyro', 'confectionery']) {
    test(`billing screens: ${theme}, ${width}px`, async ({ page }) => {
      await page.setViewportSize({ width: 1440, height: 1000 });
      await chooseTheme(page, theme);
      await page.setViewportSize({ width, height: 1000 });
      for (const [screen, url] of [
        ['plan', '/billing'],
        ['usage', '/billing/usage'],
        ['plans', '/billing/plans'],
        ['invoices', '/billing/invoices'],
        ['payer', '/billing/payer'],
      ]) {
        const response = await page.goto(url);
        expect(response?.status()).toBe(200);
        await expect(page.locator('main h1')).toBeVisible();
        await expect(page.locator('html')).toHaveAttribute('data-theme', theme);
        await checkLayout(page);
        await checkAccessibility(page);
        await page.screenshot({
          path: `.local/screenshots/e1-07/${screen}-${theme}-${width}.png`,
          fullPage: true,
          animations: 'disabled',
        });
      }
    });
  }
}

test('payer form validates and persists through a real session', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.goto('/billing/payer');
  await page.getByLabel('Юридическое название').fill('Тестовая организация');
  await page.getByLabel('ИНН', { exact: true }).fill('123');
  await page.getByRole('button', { name: 'Сохранить реквизиты' }).click();
  await expect(page.getByText('ИНН должен содержать 10 или 12 цифр.').first()).toBeVisible();
  await page.getByLabel('ИНН', { exact: true }).fill('1234567890');
  await page.getByLabel('КПП', { exact: true }).fill('123456789');
  await page.getByLabel('Юридический адрес').fill('Тестовый адрес');
  await page.getByLabel('Почта для счетов').fill('payer@example.test');
  await page.getByRole('button', { name: 'Сохранить реквизиты' }).click();
  await expect(page.getByRole('status')).toContainText('Реквизиты сохранены.');
  await page.reload();
  await expect(page.getByLabel('ИНН', { exact: true })).toHaveValue('1234567890');
  await expect(page.getByLabel('Почта для счетов')).toHaveValue('payer@example.test');
  await page.screenshot({
    path: '.local/screenshots/e1-07/payer-saved-1440.png',
    fullPage: true,
    animations: 'disabled',
  });
});
