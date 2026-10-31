import { expect, test, type BrowserContext, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { mkdir, readFile } from 'node:fs/promises';

test.skip(
  process.env.RUN_BILLING_E2E !== '1',
  'Requires the isolated SP-E1-07 test database and server.',
);
type Fixture = {
  owner_email: string;
  brands: Record<string, { id: string; name: string }>;
  plan_id: string;
  plan_name: string;
};
let fixture: Fixture;
let ownerCookies: Awaited<ReturnType<BrowserContext['cookies']>>;

async function localOnly(context: BrowserContext) {
  await context.route('**/*', (route) =>
    new URL(route.request().url()).origin === 'https://127.0.0.1:8107'
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
  const context = await browser.newContext({
    baseURL: 'https://127.0.0.1:8107',
    ignoreHTTPSErrors: true,
  });
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

test('plan request creates one ticket and cabinet notification and replays safely', async ({
  page,
}) => {
  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.goto('/billing/plans');
  const card = page
    .locator('article')
    .filter({ has: page.getByRole('heading', { name: fixture.plan_name }) });
  await expect(card).toBeVisible();
  const responsePromise = page.waitForResponse(
    (response) =>
      response.url().endsWith('/billing/plans/request') && response.request().method() === 'POST',
  );
  await card.getByRole('button', { name: 'Оставить заявку' }).click();
  const response = await responsePromise;
  expect(response.status()).toBe(201);
  const requestBody = response.request().postDataJSON() as {
    plan_id: string;
    submission_key: string;
  };
  const context = response.request().headers()['x-billing-context'];
  expect(context).toMatch(/^[a-f0-9]{64}$/);
  expect(requestBody.plan_id).toBe(fixture.plan_id);
  const created = (await response.json()).data as {
    ticket_id: string;
    created: boolean;
    delivery_status: string;
    next_submission_key: string;
  };
  expect(created).toMatchObject({ created: true, delivery_status: 'not_configured' });
  expect(created.ticket_id).toMatch(/^[0-9A-HJKMNP-TV-Z]{26}$/i);
  expect(created.next_submission_key).not.toBe(requestBody.submission_key);
  await expect(page.getByRole('status')).toContainText('Заявка создана.');
  await expect(page.getByRole('status')).toContainText('Уведомление не настроено.');
  await page.reload();
  await expect(page.getByRole('status')).toContainText('Заявка создана.');
  await expect(card.getByRole('button', { name: 'Оставить заявку' })).toBeDisabled();
  const replay = await page.evaluate(
    async ({ body, context }) => {
      const token = document.cookie.split('; ').find((item) => item.startsWith('XSRF-TOKEN='));
      if (!token) throw new Error('Missing test session CSRF cookie');
      const result = await fetch('/billing/plans/request', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-XSRF-TOKEN': decodeURIComponent(token.slice('XSRF-TOKEN='.length)),
          'X-Billing-Context': context,
        },
        body: JSON.stringify(body),
      });
      return { status: result.status, json: await result.json() };
    },
    { body: requestBody, context },
  );
  expect(replay.status).toBe(200);
  expect(replay.json.data).toMatchObject({
    ticket_id: created.ticket_id,
    created: false,
    delivery_status: 'not_configured',
  });

  const env: NodeJS.ProcessEnv = {
    ...process.env,
    APP_ENV: 'testing',
    E107_FIXTURE_ENABLED: '1',
    E107_FIXTURE_ORG: '01J00000000000000000000000',
    SUPPORT_MAIL_ENABLED: 'false',
    SUPPORT_SMTP_ENABLED: 'false',
    NOTIFICATIONS_EXTERNAL_ENABLED: 'false',
    MAIL_MAILER: 'log',
  };
  delete env.SUPPORT_RECIPIENT;
  const inspect = (mode: 'inspect' | 'process') =>
    JSON.parse(
      execFileSync(
        'C:/OSPanel/modules/PHP-8.4/php.exe',
        [
          '-d',
          `sys_temp_dir=${process.cwd()}/.local/tmp`,
          'tests/Browser/E1-07/inspect-plan-request.php',
          created.ticket_id,
          mode,
        ],
        { encoding: 'utf8', env },
      ),
    ) as Record<string, unknown>;
  expect(inspect('inspect')).toMatchObject({
    ticket_count: 1,
    delivery_status: 'not_configured',
    outbox_count: 1,
    outbox_processed: true,
    audit_count: 1,
    cabinet_delivery_count: 1,
    external_delivery_count: 0,
    cabinet_notification_count: 1,
  });
  expect(inspect('process')).toMatchObject({
    ticket_count: 1,
    delivery_status: 'not_configured',
    outbox_count: 1,
    outbox_processed: true,
    audit_count: 1,
    cabinet_delivery_count: 1,
    external_delivery_count: 0,
    cabinet_notification_count: 1,
  });
  await page.screenshot({
    path: '.local/screenshots/e1-07/plan-request-confirmed-1440.png',
    fullPage: true,
    animations: 'disabled',
  });
  await page.goto('/journal/notifications');
  await expect(page.getByText('Заявка на смену тарифа создана').first()).toBeVisible();
});
