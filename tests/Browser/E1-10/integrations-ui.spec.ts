import { expect, test, type Page } from '@playwright/test';
const origin = process.env.E110_FIXTURE_ORIGIN ?? 'http://127.0.0.1:5237';
const url = origin + '/tests/Browser/E1-10/ui-fixture.html';
test.use({
  launchOptions: process.env.E110_CHROMIUM_PATH
    ? { executablePath: process.env.E110_CHROMIUM_PATH }
    : {},
});
test.beforeEach(async ({ page }) => {
  await page.route('**/*', (route) =>
    new URL(route.request().url()).origin === origin
      ? route.continue()
      : route.abort('blockedbyclient'),
  );
});
test('статус чтения бренда отделён от недоступных диалогов и вебхуков', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 900 });
  await page.goto(`${url}?screen=api&brand-api-available=1`);
  await expect(page.getByText(/Маршрут чтения текущего бренда настроен/)).toBeVisible();
  await expect(page.getByText('Запросы API для работы с диалогами и лидами пока недоступны.')).toBeVisible();
  await expect(page.getByText(/Настройка и отправка вебхуков временно недоступны/)).toBeVisible();
  await readable(page);
  await page.screenshot({ path: '.local/screenshots/e1-10/api-brand-read-configured-390.png', fullPage: true });
  await page.goto(`${url}?screen=api`);
  await expect(page.getByText('Чтение бренда через API сейчас не подтверждено или недоступно.')).toBeVisible();
  await expect(page.getByText(/Маршрут чтения текущего бренда настроен/)).toHaveCount(0);
});
async function readable(page: Page) {
  expect(
    await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
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
      .map((node) => node.outerHTML.slice(0, 160)),
  );
  expect(small).toEqual([]);
}
for (const width of [1440, 1024, 390])
  for (const theme of ['neutral', 'pyro', 'confectionery'])
    for (const screen of ['index', 'api', 'notifications']) {
      test(`${screen} ${theme} ${width}`, async ({ page }) => {
        await page.setViewportSize({ width, height: 900 });
        await page.goto(`${url}?screen=${screen}&theme=${theme}`);
        await expect(
          page.getByRole('heading', {
            level: 1,
            name:
              screen === 'index'
                ? 'Подключения'
                : screen === 'api'
                  ? 'API-ключи и вебхуки'
                  : 'Уведомления компании',
          }),
        ).toBeVisible();
        if (screen === 'api') {
          await expect(page.getByText('Доставлено (200)')).toBeVisible();
          await expect(page.getByText(/Тестовая отправка/)).toBeVisible();
          await expect(page.getByRole('button', { name: 'Отправить тест' })).toBeDisabled();
        }
        if (screen === 'api') {
          await expect(page.getByText('Запросы API для работы с диалогами и лидами пока недоступны.')).toBeVisible();
          await expect(page.getByText('Адрес скрыт', { exact: true })).toBeVisible();
          await expect(page.getByRole('button', { name: 'Добавить вебхук' })).toHaveCount(0);
          await expect(page.getByRole('button', { name: 'Отправить тест' })).toBeDisabled();
        }
        if (screen === 'notifications') {
          await expect(
            page.getByText('Внешняя доставка пока выключена. Проверка канала работает без отправки сообщений.'),
          ).toBeVisible();
          await expect(page.getByRole('button', { name: 'Сохранить' })).toBeEnabled();
        }
        await readable(page);
        await page.screenshot({
          path: `.local/screenshots/e1-10/${screen}-${theme}-${width}.png`,
          fullPage: true,
        });
      });
    }
test('keyboard modal and WCAG checks', async ({ page }) => {
  await page.goto(`${url}?screen=api`);
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
  await page.getByRole('button', { name: 'Создать ключ' }).click();
  await expect(page.getByRole('dialog', { name: 'Создать API-ключ' })).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(page.getByRole('button', { name: 'Создать ключ' })).toBeFocused();
});
test('one-time secret stays out of history and an expired secret reports an error', async ({
  page,
  context,
}) => {
  await context.addCookies([{ name: 'XSRF-TOKEN', value: 'fixture-csrf', url: origin }]);
  let count = 0;
  await page.route('**/integrations/api/secrets', async (route) => {
    expect(route.request().headers()['x-xsrf-token']).toBe('fixture-csrf');
    expect(route.request().postDataJSON()).toEqual({ type: 'api_key' });
    count += 1;
    await route.fulfill({
      status: count === 1 ? 200 : 404,
      contentType: 'application/json',
      body: JSON.stringify(count === 1 ? { value: 'fixture-secret-123' } : { message: 'expired' }),
    });
  });
  await page.goto(`${url}?screen=api&secret=1`);
  await page.getByRole('button', { name: 'Показать ключ один раз' }).click();
  await expect(page.getByRole('dialog', { name: 'Сохраните ключ' })).toContainText(
    'fixture-secret-123',
  );
  expect(JSON.stringify(await page.evaluate(() => history.state))).not.toContain(
    'fixture-secret-123',
  );
  await page.getByRole('button', { name: 'Готово' }).click();
  await expect(page.getByText('fixture-secret-123')).toHaveCount(0);
  await page.evaluate(() => history.pushState({ fixture: true }, '', '#next'));
  await page.goBack();
  expect(JSON.stringify(await page.evaluate(() => history.state))).not.toContain(
    'fixture-secret-123',
  );
  await page.goForward();
  expect(JSON.stringify(await page.evaluate(() => history.state))).not.toContain(
    'fixture-secret-123',
  );
  await page.goto(`${url}?screen=api&secret=1`);
  await page.getByRole('button', { name: 'Показать ключ один раз' }).click();
  await expect(page.getByRole('alert')).toContainText('создайте новый');
});

test('existing channel statuses use Russian labels and planned channels are unavailable', async ({
  page,
}) => {
  await page.goto(`${url}?screen=index&channel-statuses=1`);
  const card = (title: string) =>
    page.locator('article').filter({ has: page.getByRole('heading', { name: title }) });
  await expect(card('Канал без настройки')).toContainText('Не подключён');
  await expect(card('Выключенный канал')).toContainText('Выключен');
  await expect(card('Планируемый канал')).toContainText('Скоро');
  await expect(card('Планируемый канал').getByRole('link', { name: 'Настроить' })).toHaveCount(0);
  await expect(card('Неизвестный канал')).toContainText('Статус неизвестен');
  await expect(card('Неизвестный канал')).not.toContainText('future_internal_code');
});

test('insecure transport explains why secret actions are unavailable', async ({ page }) => {
  await page.goto(`${url}?screen=api&insecure=1&secret=1`);
  await expect(
    page.getByText(
      'Для создания и просмотра секретов откройте кабинет по HTTPS или через localhost на этом компьютере.',
    ),
  ).toBeVisible();
  await expect(page.getByRole('button', { name: 'Создать ключ' })).toBeDisabled();
  await expect(page.getByRole('button', { name: 'Добавить вебхук' })).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Показать ключ один раз' })).toBeDisabled();
  await expect(page.getByRole('button', { name: 'Показать секрет один раз' })).toBeDisabled();
  await readable(page);
  await page.screenshot({ path: '.local/screenshots/e1-10/api-insecure.png', fullPage: true });
});

test('unsafe session storage prevents secret creation and gives clear feedback', async ({
  page,
}) => {
  await page.goto(`${url}?screen=api&unsafe-storage=1&secret=1`);
  await expect(
    page.getByText(
      'Показ секретов временно недоступен: требуется безопасное хранилище сессий и блокировок.',
    ),
  ).toBeVisible();
  await expect(page.getByRole('button', { name: 'Создать ключ' })).toBeDisabled();
  await expect(page.getByRole('button', { name: 'Добавить вебхук' })).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Показать ключ один раз' })).toBeDisabled();
});
