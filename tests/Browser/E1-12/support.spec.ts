import { expect, test, type BrowserContext, type Page } from '@playwright/test';
import { readFileSync } from 'node:fs';

const enabled = process.env.RUN_SUPPORT_E2E === '1';
test.skip(!enabled, 'Requires the dedicated SP-E1-12 test database, fixture and server.');
type Fixture = {
  brands: Record<string, { id: string; name: string }>;
  owner_email: string;
  viewer_email: string;
  editor_email: string;
};
let fixture: Fixture;
let ownerCookies: Awaited<ReturnType<BrowserContext['cookies']>>;
const png = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j6GkAAAAASUVORK5CYII=',
  'base64',
);
async function localOnly(context: BrowserContext) {
  await context.route('**/*', (route) =>
    ['127.0.0.1', 'localhost'].includes(new URL(route.request().url()).hostname)
      ? route.continue()
      : route.abort(),
  );
}
async function login(page: Page, email: string) {
  await page.goto('/login');
  await page.getByLabel('Рабочая почта').fill(email);
  await page.getByLabel('Пароль', { exact: true }).fill('test-only-password-123');
  await page.getByRole('button', { name: 'Войти', exact: true }).click();
  await expect(page).toHaveURL(/\/auth\/ready$/);
}
async function theme(page: Page, key: string) {
  await page.goto('/help');
  if ((await page.locator('html').getAttribute('data-theme')) !== key) {
    await page.getByRole('combobox', { name: 'Выбрать бренд', exact: true }).click();
    await page.getByRole('option', { name: fixture.brands[key].name, exact: true }).click();
    await expect(page).toHaveURL(/\/auth\/ready$/);
    await page.goto('/help');
  }
  await expect(page.locator('html')).toHaveAttribute('data-theme', key);
}
async function layout(page: Page) {
  await page.evaluate(() => document.fonts.ready.then(() => true));
  expect(
    await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1),
  ).toBeTruthy();
  const small = await page.locator('body').evaluate((body) =>
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
      .map((node) => node.tagName + ':' + getComputedStyle(node).fontSize),
  );
  expect(small).toEqual([]);
}
async function accessible(page: Page) {
  await page.addScriptTag({ path: 'node_modules/axe-core/axe.min.js' });
  const violations = await page.evaluate(async () => {
    const axe = (
      window as unknown as {
        axe: {
          run: (
            context: Document,
            options: object,
          ) => Promise<{ violations: { id: string; nodes: { target: string[] }[] }[] }>;
        };
      }
    ).axe;
    return (
      await axe.run(document, {
        runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21aa'] },
      })
    ).violations.map(({ id, nodes }) => ({ id, targets: nodes.map((node) => node.target) }));
  });
  expect(violations).toEqual([]);
}
async function choose(page: Page, name: string, option: number) {
  await page.getByRole('combobox', { name, exact: true }).click();
  await expect(page.getByRole('listbox')).toBeVisible();
  await page.getByRole('option').nth(option).click();
}
async function fillTicket(page: Page, text: string) {
  await choose(page, 'Тема', 0);
  await choose(page, 'Модуль', 0);
  await page.getByLabel('Описание', { exact: true }).fill(text);
}

test.beforeAll(async ({ browser }) => {
  fixture = JSON.parse(readFileSync('.local/runtime/e1-12-browser.json', 'utf8')) as Fixture;
  const context = await browser.newContext({
    baseURL: process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8212',
  });
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
    test(`support screens: ${color}, ${width}px, readable and accessible`, async ({ page }) => {
      await theme(page, color);
      await page.setViewportSize({ width, height: 1000 });
      for (const [screen, path] of [
        ['help', '/help'],
        ['tickets', '/help/support'],
        ['status', '/help/status'],
      ]) {
        await page.goto(path);
        await expect(page.locator('main h1')).toBeVisible();
        await expect(page.locator('html')).toHaveAttribute('data-theme', color);
        await layout(page);
        await accessible(page);
        await page.screenshot({
          path: `.local/screenshots/e1-12/${screen}-${color}-${width}.png`,
          fullPage: true,
          animations: 'disabled',
        });
      }
    });
  }
}

test('ticket, private attachment and consent persist; revocation takes effect', async ({
  page,
  browser,
}) => {
  await theme(page, 'neutral');
  await page.goto('/help/support');
  const text = `Проверка сохранения обращения ${Date.now()}. Не удаётся открыть раздел помощи.`;
  await fillTicket(page, text);
  await page
    .locator('input[type=file]')
    .setInputFiles({ name: 'диагностика-экрана.png', mimeType: 'image/png', buffer: png });
  await page
    .getByRole('checkbox', {
      name: 'Сохранить согласие на доступ поддержки на 72 часа',
      exact: true,
    })
    .check();
  await page.getByRole('button', { name: 'Отправить обращение', exact: true }).click();
  const card = page.locator('[data-ticket-id]').filter({ hasText: text });
  await expect(card).toBeVisible();
  const id = await card.getAttribute('data-ticket-id');
  expect(id).toBeTruthy();
  if ((await card.getAttribute('open')) === null) {
    await card.locator('summary').click();
  }
  await expect(card.getByRole('button', { name: 'Отозвать согласие', exact: true })).toBeVisible();
  await page.reload();
  await expect(card).toBeVisible();
  if ((await card.getAttribute('open')) === null) {
    await card.locator('summary').click();
  }
  const link = card.getByRole('link').filter({ hasText: 'диагностика-экрана.png' });
  const href = await link.getAttribute('href');
  expect(href).toMatch(/\/help\/support\/[^/]+\/attachments\//);
  const file = await page.request.get(href!);
  expect(file.status()).toBe(200);
  expect(file.headers()['content-disposition']).toContain('attachment');
  expect(file.headers()['x-content-type-options']).toBe('nosniff');
  expect(await file.body()).toEqual(png);
  await page.screenshot({
    path: '.local/screenshots/e1-12/ticket-created-neutral-1440.png',
    fullPage: true,
  });
  const viewer = await browser.newContext({
    baseURL: process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8212',
  });
  await localOnly(viewer);
  try {
    const other = await viewer.newPage();
    await login(other, fixture.viewer_email);
    await other.goto('/help/support');
    await expect(
      other.getByText(
        'Создание обращений для вашей роли пока недоступно. Вы можете просматривать свои обращения.',
        { exact: true },
      ),
    ).toBeVisible();
    await expect(
      other.getByRole('button', { name: 'Отправить обращение', exact: true }),
    ).toHaveCount(0);
    await expect(other.locator(`[data-ticket-id="${id}"]`)).toHaveCount(0);
    expect((await other.request.get(href!)).status()).toBe(404);
  } finally {
    await viewer.close();
  }
  await card.getByRole('button', { name: 'Отозвать согласие', exact: true }).click();
  await page
    .getByRole('dialog')
    .getByRole('button', { name: 'Отозвать согласие', exact: true })
    .click();
  await expect(page.getByRole('status').filter({ hasText: 'Согласие отозвано.' })).toBeVisible();
  await expect(page.locator('div[aria-busy=true]')).toHaveCount(0);
  await expect(card).toBeVisible();
  await expect(card).toHaveAttribute('open', '');
  await expect(card.getByText('Отозвано', { exact: true }).first()).toBeVisible();
  await page.reload();
  await expect(card).toBeVisible();
  if ((await card.getAttribute('open')) === null) {
    await card.locator('summary').click();
  }
  await expect(card.getByText('Отозвано', { exact: true }).first()).toBeVisible();
  await expect(card.getByRole('button', { name: 'Отозвать согласие', exact: true })).toHaveCount(0);
  await page.setViewportSize({ width: 390, height: 844 });
  await layout(page);
  await accessible(page);
  await page.screenshot({
    path: '.local/screenshots/e1-12/ticket-revoked-neutral-390.png',
    fullPage: true,
  });
});

test('unconfigured help keeps search disabled', async ({ page }) => {
  await theme(page, 'neutral');
  await expect(page.getByLabel('Поиск по разделам и статьям', { exact: true })).toBeDisabled();
  await expect(page.getByText('База знаний пока не подключена', { exact: true })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Сбросить поиск', exact: true })).toHaveCount(0);
  await page.screenshot({
    path: '.local/screenshots/e1-12/help-unconfigured.png',
    fullPage: true,
  });
});
test('mobile submission loading and network error remain visible and recoverable', async ({
  page,
}) => {
  await theme(page, 'neutral');
  await page.goto('/help/support');
  await page.setViewportSize({ width: 390, height: 844 });
  await fillTicket(page, 'Не удаётся получить ответ при отправке обращения в поддержку.');
  let release!: () => void;
  const hold = new Promise<void>((resolve) => {
    release = resolve;
  });
  await page.route('**/help/support', async (route) => {
    if (route.request().method() !== 'POST') return route.continue();
    await hold;
    await route.abort('failed');
  });
  const submit = page.locator('form button[type=submit]');
  await submit.click();
  await expect(submit).toBeDisabled();
  await page.screenshot({
    path: '.local/screenshots/e1-12/ticket-loading-390.png',
    fullPage: false,
  });
  release();
  const alert = page.getByRole('alert');
  await expect(alert).toBeInViewport();
  await expect(alert).toBeFocused();
  await expect(alert).toContainText('Не удалось подтвердить результат.');
  await expect(submit).toBeEnabled();
  await expect(page.getByLabel('Описание', { exact: true })).toHaveValue(
    'Не удаётся получить ответ при отправке обращения в поддержку.',
  );
  await page.screenshot({ path: '.local/screenshots/e1-12/ticket-error-390.png', fullPage: false });
});

test('status refresh shows loading, handles failure and recovers', async ({ page }) => {
  await theme(page, 'neutral');
  await page.goto('/help/status');
  let release!: () => void;
  const hold = new Promise<void>((resolve) => {
    release = resolve;
  });
  await page.route('**/help/status', async (route) => {
    await hold;
    await route.abort('failed');
  });
  const refresh = page.getByRole('button', { name: 'Обновить', exact: true });
  await refresh.click();
  await expect(page.locator('div[aria-busy=true]')).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/e1-12/status-loading.png', fullPage: true });
  release();
  await expect(page.getByRole('alert')).toBeVisible();
  await expect(refresh).toBeEnabled();
  await page.screenshot({ path: '.local/screenshots/e1-12/status-error.png', fullPage: true });
  await page.unroute('**/help/status');
  await refresh.click();
  await expect(page.getByRole('alert')).toHaveCount(0);
  await expect(page.locator('[data-component-id]').first()).toBeVisible();
});

test('support selectors support keyboard and invalid form identifies the problem', async ({
  page,
}) => {
  await theme(page, 'neutral');
  await page.goto('/help/support');
  const topic = page.getByRole('combobox', { name: 'Тема', exact: true });
  await topic.focus();
  await page.keyboard.press('Enter');
  await expect(page.getByRole('listbox')).toBeVisible();
  await page.keyboard.press('Home');
  await expect(page.getByRole('option').first()).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(topic).toBeFocused();
  await choose(page, 'Модуль', 0);
  await page.getByRole('button', { name: 'Отправить обращение', exact: true }).click();
  await expect(page.getByRole('alert')).toBeInViewport();
  await expect(page.getByLabel('Описание', { exact: true })).toHaveValue('');
  await page.getByLabel('Описание', { exact: true }).fill('Мало');
  await page.getByRole('button', { name: 'Отправить обращение', exact: true }).click();
  await expect(page.getByRole('alert')).toContainText(
    'Описание должно содержать минимум 10 символов.',
  );
  await expect(page.getByRole('alert')).toBeInViewport();
  await page.screenshot({ path: '.local/screenshots/e1-12/ticket-validation.png', fullPage: true });
});
