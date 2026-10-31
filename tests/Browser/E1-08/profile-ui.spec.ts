import { expect, test, type Page } from '@playwright/test';
import type { NotificationSettings } from '../../../resources/js/types/Shell/Profile';

const url =
  process.env.PROFILE_FIXTURE_URL || 'http://127.0.0.1:5238/tests/Browser/E1-08/ui-fixture.html';
test.use({
  launchOptions: process.env.PROFILE_CHROMIUM_PATH
    ? { executablePath: process.env.PROFILE_CHROMIUM_PATH }
    : {},
});
test.beforeEach(async ({ page }) => {
  await page.route('**/*', (route) => {
    const target = new URL(route.request().url());
    return ['http://127.0.0.1:5238', 'http://127.0.0.1:8238'].includes(target.origin)
      ? route.continue()
      : route.abort('blockedbyclient');
  });
});
async function checkLayout(page: Page) {
  expect(
    await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
  ).toBeTruthy();
  const small = await page.locator('body').evaluate((body) =>
    Array.from(body.querySelectorAll('*'))
      .filter((node) => {
        const style = getComputedStyle(node),
          rect = node.getBoundingClientRect();
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
      .map((node) => node.outerHTML.slice(0, 150)),
  );
  expect(small).toEqual([]);
}
async function checkAxe(page: Page) {
  await page.addScriptTag({ path: 'node_modules/axe-core/axe.min.js' });
  const violations = await page.evaluate(
    async () =>
      (
        await (
          window as unknown as {
            axe: {
              run: (root: Document, options: object) => Promise<{ violations: { id: string }[] }>;
            };
          }
        ).axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa'] } })
      ).violations,
  );
  expect(violations).toEqual([]);
}
for (const width of [1440, 1024, 390])
  for (const theme of ['confectionery', 'pyro', 'neutral']) {
    test(`profile pages fit ${theme} ${width}`, async ({ page }) => {
      await page.setViewportSize({ width, height: 1000 });
      for (const screen of ['profile', 'security', 'notifications']) {
        await page.goto(`${url}?screen=${screen}&theme=${theme}`);
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
        await checkLayout(page);
        if (width !== 1024) await checkAxe(page);
        await page.screenshot({
          path: `.local/screenshots/e1-08/${screen}-${theme}-${width}.png`,
          fullPage: true,
        });
      }
    });
  }
test('profile dialogs and timezone select support keyboard and restore focus', async ({ page }) => {
  await page.goto(url);
  await page.getByRole('combobox', { name: 'Часовой пояс' }).focus();
  await page.keyboard.press('Enter');
  await page.keyboard.press('ArrowDown');
  await page.keyboard.press('Enter');
  await expect(page.getByRole('combobox', { name: 'Часовой пояс' })).toBeFocused();
  await page.getByRole('button', { name: 'Изменить почту' }).click();
  await expect(page.getByRole('dialog', { name: 'Изменить почту' })).toBeVisible();
  await checkAxe(page);
  await page.screenshot({
    path: '.local/screenshots/e1-08/profile-email-modal.png',
    fullPage: true,
  });
  await page.getByLabel('Текущий пароль').fill('fixture-secret');
  await page.keyboard.press('Escape');
  await expect(page.getByRole('button', { name: 'Изменить почту' })).toBeFocused();
  await page.getByRole('button', { name: 'Изменить почту' }).click();
  await expect(page.getByLabel('Текущий пароль')).toHaveValue('');
});
test('security confirmation and MFA states are accessible', async ({ page }) => {
  await page.goto(`${url}?screen=security`);
  await page.getByRole('button', { name: 'Завершить все, кроме текущей' }).click();
  await expect(page.getByRole('dialog', { name: 'Завершить остальные сессии?' })).toBeVisible();
  await checkAxe(page);
  await page.screenshot({
    path: '.local/screenshots/e1-08/security-revoke-confirm.png',
    fullPage: true,
  });
  await page.keyboard.press('Escape');
  await page.getByRole('button', { name: 'Включить защиту' }).click();
  await expect(page.getByRole('dialog', { name: 'Настроить двухфакторную защиту' })).toBeVisible();
  await page.screenshot({
    path: '.local/screenshots/e1-08/security-mfa-password.png',
    fullPage: true,
  });
  await page.goto(`${url}?screen=security&setup=1`);
  await expect(
    page.getByRole('img', { name: 'QR-код для приложения-аутентификатора' }),
  ).toBeVisible();
  await checkAxe(page);
  await page.screenshot({ path: '.local/screenshots/e1-08/security-mfa-qr.png', fullPage: true });
  await page.setViewportSize({ width: 390, height: 900 });
  await checkLayout(page);
  await page.screenshot({
    path: '.local/screenshots/e1-08/security-mfa-qr-390.png',
    fullPage: true,
  });
  await page.goto(`${url}?screen=security&enabled=1`);
  await page.getByRole('button', { name: 'Отключить защиту' }).click();
  await expect(page.getByRole('dialog', { name: 'Отключить двухфакторную защиту?' })).toBeVisible();
  await page.screenshot({
    path: '.local/screenshots/e1-08/security-mfa-disable.png',
    fullPage: true,
  });
});
test('empty states and Telegram pending confirmation are honest', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 900 });
  await page.goto(`${url}?screen=notifications&empty=1&unconfigured=1`);
  await expect(page.getByText('Нет доступных событий')).toBeVisible();
  await expect(page.getByText('Подключение Telegram пока недоступно.')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Отправить тестовое' })).toBeEnabled();
  await checkLayout(page);
  await page.screenshot({
    path: '.local/screenshots/e1-08/notifications-empty-390.png',
    fullPage: true,
  });
  await page.goto(`${url}?screen=security&empty=1`);
  await expect(page.getByText('Нет активных сессий')).toBeVisible();
  await page.screenshot({
    path: '.local/screenshots/e1-08/security-empty-390.png',
    fullPage: true,
  });
  await page.goto(`${url}?screen=notifications&pending=1`);
  await expect(page.getByText('Иван Петров (@ivan_test)')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Подтвердить подключение' })).toBeVisible();
  await page.screenshot({
    path: '.local/screenshots/e1-08/telegram-confirm-390.png',
    fullPage: true,
  });
});
test('profile save displays loading and recoverable transport error without losing draft', async ({
  page,
}) => {
  let finish: (() => void) | undefined;
  const pending = new Promise<void>((resolve) => {
    finish = resolve;
  });
  await page.route('**/profile', async (route) => {
    if (route.request().method() === 'PUT') {
      await pending;
      return route.abort('failed');
    }
    return route.continue();
  });
  await page.goto(url);
  await page.getByLabel('Имя', { exact: true }).fill('Новое имя');
  await page.getByRole('button', { name: 'Сохранить изменения', exact: true }).click();
  await expect(
    page.getByRole('button', { name: 'Сохранить изменения', exact: true }),
  ).toHaveAttribute('aria-busy', 'true');
  await page.screenshot({ path: '.local/screenshots/e1-08/profile-loading.png', fullPage: true });
  finish?.();
  await expect(page.getByRole('alert')).toContainText('Не удалось связаться с сервером');
  await expect(page.getByLabel('Имя', { exact: true })).toHaveValue('Новое имя');
  await page.screenshot({
    path: '.local/screenshots/e1-08/profile-network-error.png',
    fullPage: true,
  });
});

async function notificationResponse(page: Page) {
  return (await page
    .locator('script[data-page="app"]')
    .evaluate((node) => JSON.parse(node.textContent ?? '{}'))) as {
    props: { notificationSettings: NotificationSettings; status: string | null };
    url: string;
  };
}
test('timing draft makes its delivery limitation clear and sends the current brand revision', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 1000 });
  await page.goto(url + '?screen=notifications');
  await expect(page.getByText('Настройки можно сохранить как черновик. Тихие часы и ежедневная сводка пока не влияют на доставку уведомлений.')).toBeVisible();
  await page.getByLabel('Начало тихих часов').fill('22:30');
  await page.getByLabel('Окончание тихих часов').fill('07:15');
  await page.getByLabel('Время ежедневной сводки').fill('08:45');
  const response = await notificationResponse(page);
  await page.route('**/profile/notifications/timing', async (route) => {
    expect(route.request().method()).toBe('PUT');
    expect(route.request().headers()['x-brand-revision']).toBe('a'.repeat(64));
    expect(route.request().postDataJSON()).toEqual({ quiet_start: '22:30', quiet_end: '07:15', digest_time: '08:45' });
    response.props.notificationSettings.timingDraft = { quiet_start: '22:30', quiet_end: '07:15', digest_time: '08:45' };
    response.props.status = 'Черновик сохранён. Доставка пока работает без этих настроек.';
    response.url = '/profile/notifications';
    await route.fulfill({ json: response, headers: { 'X-Inertia': 'true' } });
  });
  await page.getByRole('button', { name: 'Сохранить черновик' }).click();
  await expect(page.getByLabel('Время ежедневной сводки')).toHaveValue('08:45');
  await expect(page.getByText('Черновик сохранён. Доставка пока работает без этих настроек.').first()).toBeVisible();
  await checkLayout(page);
  await checkAxe(page);
  await page.screenshot({ path: '.local/screenshots/e1-08/timing-draft-390.png', fullPage: true });
});
test('timing draft network failure keeps the unsaved input and shows an error', async ({ page }) => {
  await page.goto(url + '?screen=notifications');
  await page.getByLabel('Время ежедневной сводки').fill('08:45');
  await page.route('**/profile/notifications/timing', (route) => route.abort('failed'));
  await page.getByRole('button', { name: 'Сохранить черновик' }).click();
  await expect(page.getByRole('alert')).toContainText('Не удалось получить ответ сервера. Обновите страницу');
  await expect(page.getByLabel('Время ежедневной сводки')).toHaveValue('08:45');
});
test('Telegram disconnect updates saved channels and preserves unrelated drafts before save', async ({
  page,
}) => {
  await page.goto(url + '?screen=notifications&connected=1');
  const response = await notificationResponse(page);
  const cabinet = page.getByRole('switch', {
    name: 'Диалог ждёт менеджера: В кабинете',
    exact: true,
  });
  const email = page.getByRole('switch', { name: 'Диалог ждёт менеджера: Почта', exact: true });
  const telegram = page.getByRole('switch', { name: 'Обещание просрочено: Telegram', exact: true });
  await cabinet.click();
  await email.click();
  await expect(telegram).toHaveAttribute('aria-checked', 'true');
  await page.route('**/profile/notifications/telegram', async (route) => {
    expect(route.request().method()).toBe('DELETE');
    response.props.notificationSettings.telegram.connected = false;
    response.props.notificationSettings.events.forEach((event) => {
      event.channels.telegram = false;
    });
    response.props.status = 'Telegram отключён.';
    response.url = '/profile/notifications';
    await route.fulfill({ json: response, headers: { 'X-Inertia': 'true' } });
  });
  await page.getByRole('button', { name: 'Отключить Telegram', exact: true }).click();
  await page
    .getByRole('dialog')
    .getByRole('button', { name: 'Отключить Telegram', exact: true })
    .click();
  await expect(page.getByRole('dialog')).toHaveCount(0);
  await expect(telegram).toHaveAttribute('aria-checked', 'false');
  await expect(cabinet).toHaveAttribute('aria-checked', 'false');
  await expect(email).toHaveAttribute('aria-checked', 'false');
  await email.click();
  await page.route('**/profile/notifications', async (route) => {
    expect(route.request().method()).toBe('PUT');
    const { changes } = route.request().postDataJSON() as {
      changes: { event: string; channel: string; enabled: boolean }[];
    };
    expect(
      changes.filter((change) => change.channel === 'telegram').every((change) => !change.enabled),
    ).toBeTruthy();
    expect(
      changes.find(
        (change) =>
          change.event === 'seller.conversation.handed_off' && change.channel === 'cabinet',
      )?.enabled,
    ).toBe(false);
    expect(
      changes.find(
        (change) => change.event === 'seller.conversation.handed_off' && change.channel === 'email',
      )?.enabled,
    ).toBe(true);
    response.props.status = 'Настройки сохранены.';
    await route.fulfill({ json: response, headers: { 'X-Inertia': 'true' } });
  });
  await page.getByRole('button', { name: 'Сохранить изменения', exact: true }).click();
  await expect(page.getByRole('status')).toContainText('сохран');
});
test('test notification uses independent UUIDv4 keys when randomUUID is unavailable', async ({
  page,
}) => {
  await page.addInitScript(() => {
    Object.defineProperty(crypto, 'randomUUID', { value: undefined });
  });
  await page.goto(url + '?screen=notifications');
  const response = await notificationResponse(page);
  const keys: string[] = [];
  await page.route('**/profile/notifications/test', async (route) => {
    const data = route.request().postDataJSON() as { request_key: string; channel: string };
    expect(data.request_key).toMatch(
      /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/,
    );
    expect(keys).not.toContain(data.request_key);
    keys.push(data.request_key);
    response.props.notificationSettings.testNotifications.push({
      id: data.request_key,
      channel: 'cabinet',
      status: 'sent',
      created_at: '2026-10-31T09:00:00Z',
    });
    response.url = '/profile/notifications';
    await route.fulfill({ json: response, headers: { 'X-Inertia': 'true' } });
  });
  for (const count of [1, 2]) {
    await page.getByRole('button', { name: 'Отправить тестовое', exact: true }).click();
    await expect(page.getByText('Доставлено', { exact: true })).toHaveCount(count);
  }
  expect(keys).toHaveLength(2);
});
test('MFA setup and email change require private browser history support', async ({ page }) => {
  await page.goto(url + '?insecureCanonical=1');
  await expect(page.getByRole('button', { name: 'Изменить почту' })).toBeDisabled();
  await expect(
    page.getByText(
      'Подтверждение почты пока недоступно по защищённому адресу. Обратитесь к администратору.',
    ),
  ).toBeVisible();
  await page.addInitScript(() => {
    Object.defineProperty(crypto, 'subtle', { value: undefined });
  });
  await page.goto(url);
  await expect(page.getByRole('button', { name: 'Изменить почту' })).toBeDisabled();
  await expect(
    page.getByText('Для смены почты откройте кабинет по защищённому HTTPS-адресу.'),
  ).toBeVisible();
  await page.goto(url + '?screen=security&setup=1');
  await expect(page.getByRole('button', { name: 'Включить защиту' })).toBeDisabled();
  await expect(
    page.getByRole('img', { name: 'QR-код для приложения-аутентификатора' }),
  ).toHaveCount(0);
  await expect(
    page.getByText(
      'Для настройки двухфакторной защиты откройте кабинет по защищённому HTTPS-адресу.',
    ),
  ).toBeVisible();
  await page.goto(url + '?screen=security&enabled=1');
  await expect(page.getByRole('button', { name: 'Отключить защиту' })).toBeEnabled();
});
