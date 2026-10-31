import { expect, test, type BrowserContext, type Page } from '@playwright/test';
import { readFileSync } from 'node:fs';

test.skip(process.env.RUN_E506_E2E !== '1', 'Requires the dedicated E5-06 test database, fixture and local server.');

type Fixture = {
  brands: Record<string, { id: string; name: string }>;
  owner_email: string;
};
let fixture: Fixture;
let ownerCookies: Awaited<ReturnType<BrowserContext['cookies']>>;

async function localOnly(context: BrowserContext) {
  const allowed = new URL(process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8256');
  if (!['127.0.0.1', 'localhost'].includes(allowed.hostname)) {
    throw new Error('E5-06 browser tests require a local server.');
  }
  await context.route('**/*', (route) => {
    const url = new URL(route.request().url());
    return url.origin === allowed.origin ? route.continue() : route.abort('blockedbyclient');
  });
}

async function login(page: Page, email: string) {
  await page.goto('/login');
  await page.getByLabel('Рабочая почта').fill(email);
  await page.getByLabel('Пароль', { exact: true }).fill('test-only-password-123');
  await page.getByRole('button', { name: 'Войти', exact: true }).click();
  await expect(page).toHaveURL(/\/auth\/ready$/);
}

async function theme(page: Page, key: string) {
  await page.goto('/seller/channels/vk');
  const brand = page.getByRole('combobox', { name: 'Выбрать бренд', exact: true });
  // Server HTML starts at neutral; wait for React and the actual selected brand.
  await expect(brand).toBeVisible();
  if (!(await brand.innerText()).includes(fixture.brands[key].name)) {
    await brand.click();
    await page.getByRole('option', { name: fixture.brands[key].name, exact: true }).click();
    await expect(page).toHaveURL(/\/auth\/ready$/);
    await page.goto('/seller/channels/vk');
  }
  await expect(page.locator('html')).toHaveAttribute('data-theme', key);
}

async function layout(page: Page) {
  await page.evaluate(() => document.fonts.ready.then(() => true));
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBeTruthy();
  const small = await page.locator('body').evaluate((body) =>
    Array.from(body.querySelectorAll('*'))
      .filter((node) => {
        const style = getComputedStyle(node);
        const rect = node.getBoundingClientRect();
        return Array.from(node.childNodes).some((child) => child.nodeType === Node.TEXT_NODE && child.textContent?.trim())
          && style.visibility !== 'hidden' && style.display !== 'none' && rect.width > 0 && rect.height > 0
          && parseFloat(style.fontSize) < 13;
      })
      .map((node) => `${node.tagName}:${getComputedStyle(node).fontSize}`),
  );
  expect(small).toEqual([]);
}

async function accessible(page: Page) {
  await page.addScriptTag({ path: 'node_modules/axe-core/axe.min.js' });
  const violations = await page.evaluate(async () => {
    const axe = (window as unknown as {
      axe: { run: (context: Document, options: object) => Promise<{ violations: { id: string; nodes: { target: string[] }[] }[] }> };
    }).axe;
    return (await axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21aa'] } }))
      .violations.map(({ id, nodes }) => ({ id, targets: nodes.map((node) => node.target) }));
  });
  expect(violations).toEqual([]);
}

test.beforeAll(async ({ browser }) => {
  fixture = JSON.parse(readFileSync('.local/runtime/e5-06-browser.json', 'utf8')) as Fixture;
  const context = await browser.newContext({ baseURL: process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8256' });
  await localOnly(context);
  const page = await context.newPage();
  await login(page, fixture.owner_email);
  ownerCookies = await context.cookies();
  await context.close();
});
test.beforeEach(async ({ page, context }) => {
  await context.addCookies(ownerCookies);
  await localOnly(context);
  await page.setViewportSize({ width: 1440, height: 1000 });
});

for (const width of [1440, 1024, 390]) {
  for (const color of ['neutral', 'pyro', 'confectionery']) {
    test(`planned screens: ${color}, ${width}px, truthful and accessible`, async ({ page }) => {
      await theme(page, color);
      await page.setViewportSize({ width, height: 1000 });
      for (const [screen, path] of [
        ['vk', '/seller/channels/vk'],
        ['whatsapp', '/seller/channels/whatsapp'],
        ['calendar', '/seller/integrations/calendar'],
        ['planned', '/seller/integrations/planned'],
      ]) {
        await page.goto(path);
        await expect(page.locator('main h1')).toBeVisible();
        await expect(page.locator('html')).toHaveAttribute('data-theme', color);
        await expect(page.locator('main').getByText('Скоро', { exact: true }).first()).toBeVisible();
        await expect(page.locator('main').getByText(/^Подключено$/i)).toHaveCount(0);
        await layout(page);
        await accessible(page);
        await page.screenshot({ path: `.local/screenshots/e5-06/${screen}-${color}-${width}.png`, fullPage: true, animations: 'disabled' });
      }
    });
  }
}

test('keyboard can follow the future integrations link', async ({ page }) => {
  await page.goto('/seller/channels/vk');
  const link = page.getByRole('link', { name: 'Другие будущие интеграции', exact: true });
  await link.focus();
  await expect(link).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(page).toHaveURL(/\/seller\/integrations\/planned$/);
  await expect(page.locator('main h1')).toHaveText('Будущие интеграции');
});

test('interest is saved for the active brand and a stale tab is rejected', async ({ page, context }) => {
  await theme(page, 'neutral');
  const staleButton = page.getByRole('button', { name: 'Сообщить, когда появится', exact: true });
  await expect(staleButton).toBeEnabled();
  const other = await context.newPage();
  try {
    await other.goto('/seller/channels/vk');
    await other.getByRole('combobox', { name: 'Выбрать бренд', exact: true }).click();
    await other.getByRole('option', { name: fixture.brands.pyro.name, exact: true }).click();
    await expect(other).toHaveURL(/\/auth\/ready$/);
    const stale = page.waitForResponse((response) => response.url().endsWith('/seller/planned/interests') && response.request().method() === 'POST');
    await staleButton.click();
    expect((await stale).status()).toBe(404);
    await expect(page.getByRole('alert')).toContainText('Бренд или доступ изменились');
  } finally {
    await other.close();
  }
  await theme(page, 'neutral');
  const response = page.waitForResponse((result) => result.url().endsWith('/seller/planned/interests') && result.request().method() === 'POST');
  await page.getByRole('button', { name: 'Сообщить, когда появится', exact: true }).click();
  expect((await response).status()).toBe(200);
  await expect(page.getByRole('status').filter({ hasText: 'Интерес сохранён' })).toBeVisible();
  await page.reload();
  await expect(page.getByRole('status').filter({ hasText: 'Интерес сохранён' })).toBeVisible();
});
