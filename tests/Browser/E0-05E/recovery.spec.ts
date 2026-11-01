import { execFileSync } from 'node:child_process';
import { expect, test, type Page } from '@playwright/test';

test.skip(
  process.env.RUN_AI_RECOVERY_E2E !== '1' || !process.env.TEAM_PHP_PATH,
  'Requires the isolated AI recovery environment',
);
test.use({
  launchOptions: process.env.TEAM_CHROMIUM_PATH
    ? { executablePath: process.env.TEAM_CHROMIUM_PATH }
    : {},
});
test.setTimeout(60_000);
const origin = 'http://127.0.0.1:8230';
let fixture: {
  firstBrand: string;
  secondBrand: string;
  emptyBrand: string;
  firstId: string;
  secondPageId: string;
  otherId: string;
};

test.beforeAll(() => {
  const options = { encoding: 'utf8' as const, env: { ...process.env, APP_ENV: 'testing' } };
  execFileSync(process.env.TEAM_PHP_PATH!, ['tests/Browser/E1-06/prepare-fixture.php'], options);
  fixture = JSON.parse(
    execFileSync(process.env.TEAM_PHP_PATH!, ['tests/Browser/E0-05E/fixture.php'], options),
  );
});
test.beforeEach(async ({ page }) => {
  await page.route('**/*', (route) =>
    new URL(route.request().url()).origin === origin
      ? route.continue()
      : route.abort('blockedbyclient'),
  );
});
async function login(page: Page, role: 'owner' | 'viewer') {
  await page.goto(`${origin}/login`);
  await page.getByLabel('Рабочая почта').fill(`team-e106-${role}@example.test`);
  await page.getByLabel('Пароль', { exact: true }).fill('test-only-password-123');
  await page.getByRole('button', { name: 'Войти', exact: true }).click();
  await expect(page).toHaveURL(/auth\/ready/);
}
async function switchBrand(page: Page, brand: string) {
  const cookie = (await page.context().cookies(origin)).find((item) => item.name === 'XSRF-TOKEN');
  expect(cookie).toBeTruthy();
  const response = await page.request.post(`${origin}/brand/switch`, {
    form: { brand_id: brand },
    headers: { 'X-XSRF-TOKEN': decodeURIComponent(cookie!.value) },
    maxRedirects: 0,
  });
  expect(response.status()).toBe(302);
}

test('владелец открывает экран из тарифа, повторяет загрузку и листает реальные данные', async ({
  page,
}) => {
  await login(page, 'owner');
  await page.goto(`${origin}/billing`);
  const link = page.getByRole('link', { name: 'Посмотреть вызовы ИИ' });
  await expect(link).toBeVisible();
  let release!: () => void;
  let markCaptured!: () => void;
  const gate = new Promise<void>((resolve) => {
    release = resolve;
  });
  const captured = new Promise<void>((resolve) => {
    markCaptured = resolve;
  });
  let failed = false;
  await page.route('**/ai/recovery/calls*', async (route) => {
    if (!failed) {
      failed = true;
      markCaptured();
      await gate;
      await route.abort('failed');
    } else await route.continue();
  });
  await link.click();
  await captured;
  await expect(page.getByRole('status')).toContainText('загружаем вызовы');
  await page.screenshot({ path: '.local/screenshots/e0-05e/recovery-loading.png', fullPage: true });
  release();
  await expect(page.getByRole('alert')).toContainText('Не удалось подтвердить доступ');
  await page.screenshot({ path: '.local/screenshots/e0-05e/recovery-error.png', fullPage: true });
  await page.getByRole('button', { name: 'Повторить' }).click();
  await expect(page.getByText(fixture.firstId)).toBeVisible();
  const persisted = await page.evaluate(() =>
    JSON.stringify({
      history: history.state,
      local: { ...localStorage },
      session: { ...sessionStorage },
    }),
  );
  expect(persisted).not.toContain(fixture.firstId);
  await expect(page.getByText(fixture.otherId)).toHaveCount(0);
  await expect(
    page.getByText(/PRIVATE_PROVIDER|PRIVATE_MODEL|PRIVATE_RESULT|123456789/),
  ).toHaveCount(0);
  await expect(
    page.getByText(
      'Состояние вызова и резерв не подтверждают итоговую стоимость. Данные показываются только владельцу.',
    ),
  ).toBeVisible();
  for (const width of [1440, 1024, 390]) {
    await page.setViewportSize({ width, height: 900 });
    expect(
      await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
    ).toBeTruthy();
    await page.screenshot({ path: `.local/screenshots/e0-05e/recovery-${width}.png` });
  }
  for (const theme of ['neutral', 'pyro', 'confectionery']) {
    await page.evaluate((value) => {
      document.documentElement.dataset.theme = value;
    }, theme);
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
        .map((node) => node.outerHTML.slice(0, 160)),
    );
    expect(smallText).toEqual([]);
    await page.screenshot({ path: `.local/screenshots/e0-05e/recovery-${theme}-390.png` });
  }
  await page.addScriptTag({ path: 'node_modules/axe-core/axe.min.js' });
  const violations = await page.evaluate(async () =>
    (
      await (
        window as unknown as {
          axe: {
            run: (root: Document, options: object) => Promise<{ violations: { id: string }[] }>;
          };
        }
      ).axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa'] } })
    ).violations.map((item) => item.id),
  );
  expect(violations).toEqual([]);
  const next = page.getByRole('button', { name: 'Вперёд' });
  await next.scrollIntoViewIfNeeded();
  await next.focus();
  await expect(next).toBeFocused();
  const scrollBefore = await page.evaluate(() => scrollY);
  let checks = 0;
  await page.waitForResponse(
    (response) => {
      if (response.url().includes('/ai/recovery/calls') && response.status() === 200) checks++;
      return checks === 2;
    },
    { timeout: 15_000 },
  );
  await expect(next).toBeFocused();
  expect(Math.abs((await page.evaluate(() => scrollY)) - scrollBefore)).toBeLessThanOrEqual(2);
  await page.keyboard.press('Enter');
  await expect(page.getByText(fixture.secondPageId)).toBeVisible();
  await expect(page.getByText(fixture.firstId)).toHaveCount(0);
  await page.getByRole('button', { name: 'Назад', exact: true }).click();
  await expect(page.getByText(fixture.firstId)).toBeVisible();
  await switchBrand(page, fixture.emptyBrand);
  await page.reload();
  await expect(page.getByText('Вызовов с неподтверждённым результатом нет.')).toBeVisible();
  await expect(page.getByText(fixture.firstId)).toHaveCount(0);
  await page.screenshot({ path: '.local/screenshots/e0-05e/recovery-empty.png', fullPage: true });
});

test('наблюдателю не показывается переход и запрещён прямой доступ', async ({ page }) => {
  await login(page, 'viewer');
  await page.goto(`${origin}/billing`);
  await expect(page.getByRole('link', { name: 'Посмотреть вызовы ИИ' })).toHaveCount(0);
  expect((await page.request.get(`${origin}/billing/ai-recovery`)).status()).toBe(403);
  expect((await page.request.get(`${origin}/ai/recovery/calls`)).status()).toBe(403);
});

test('старый ответ не показывается после реального переключения бренда', async ({ page }) => {
  await login(page, 'owner');
  let release!: () => void;
  let markCaptured!: () => void;
  const gate = new Promise<void>((resolve) => {
    release = resolve;
  });
  const captured = new Promise<void>((resolve) => {
    markCaptured = resolve;
  });
  let held = false;
  await page.route('**/ai/recovery/calls*', async (route) => {
    if (held) {
      await route.continue();
      return;
    }
    held = true;
    const response = await route.fetch();
    markCaptured();
    await gate;
    await route.fulfill({ response });
  });
  await page.goto(`${origin}/billing/ai-recovery`);
  await captured;
  await switchBrand(page, fixture.secondBrand);
  release();
  await expect(page.getByRole('alert')).toBeVisible();
  await expect(page.getByText(fixture.firstId)).toHaveCount(0);
  await page.reload();
  await expect(page.getByText(fixture.otherId)).toBeVisible();
  await expect(page.getByText(fixture.firstId)).toHaveCount(0);
});

test('зависшая перепроверка закрывает уже показанный список', async ({ page }) => {
  await login(page, 'owner');
  await page.goto(`${origin}/billing/ai-recovery`);
  await expect(page.getByText(fixture.firstId)).toBeVisible();
  let release!: () => void;
  let markCaptured!: () => void;
  const gate = new Promise<void>((resolve) => {
    release = resolve;
  });
  const captured = new Promise<void>((resolve) => {
    markCaptured = resolve;
  });
  await page.route('**/ai/recovery/calls*', async (route) => {
    markCaptured();
    await gate;
    await route.continue().catch(() => {});
  });
  await captured;
  await expect(page.getByRole('alert')).toContainText('вовремя', { timeout: 7_000 });
  await expect(page.getByText(fixture.firstId)).toHaveCount(0);
  release();
});

test('уход в фон скрывает список, возвращение загружает его заново', async ({ page }) => {
  await login(page, 'owner');
  await page.goto(`${origin}/billing/ai-recovery`);
  await expect(page.getByText(fixture.firstId)).toBeVisible();
  await page.evaluate(() => {
    Object.defineProperty(document, 'visibilityState', { configurable: true, value: 'hidden' });
    document.dispatchEvent(new Event('visibilitychange'));
  });
  await expect(page.getByText(fixture.firstId)).toHaveCount(0);
  await expect(page.getByRole('alert')).toContainText('Контекст изменился');
  await page.evaluate(() => {
    Reflect.deleteProperty(document, 'visibilityState');
    document.dispatchEvent(new Event('visibilitychange'));
  });
  await expect(page.getByText(fixture.firstId)).toBeVisible();
});

test('после отзыва сессии открытая страница удаляет ранее показанные данные', async ({ page }) => {
  await login(page, 'owner');
  await page.goto(`${origin}/billing/ai-recovery`);
  await expect(page.getByText(fixture.firstId)).toBeVisible();
  execFileSync(process.env.TEAM_PHP_PATH!, ['tests/Browser/E0-05E/fixture.php'], {
    encoding: 'utf8',
    env: { ...process.env, APP_ENV: 'testing', E005E_REVOKE_SESSION: '1' },
  });
  await expect(page.getByRole('alert')).toBeVisible({ timeout: 15_000 });
  await expect(page.getByText(fixture.firstId)).toHaveCount(0);
});
