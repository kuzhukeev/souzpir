import { expect, test } from '@playwright/test';

test.skip(process.env.RUN_E804_LIST_E2E !== '1', 'Requires the isolated E8-04 server and fixture');
test.use({
  launchOptions: process.env.E804_CHROMIUM_PATH
    ? { executablePath: process.env.E804_CHROMIUM_PATH }
    : {},
});
const origin = 'http://127.0.0.1:8220';
const alpha = '01J00000000000000000000011';
const beta = '01J00000000000000000000022';

test.beforeEach(async ({ context, page }) => {
  await context.route('**/*', (route) =>
    new URL(route.request().url()).origin === origin
      ? route.continue()
      : route.abort('blockedbyclient'),
  );
  await page.goto(`${origin}/login`);
  await page.getByLabel('Рабочая почта').fill('e804-owner@example.test');
  await page.getByLabel('Пароль', { exact: true }).fill('test-only-password-123');
  await page.getByRole('button', { name: 'Войти', exact: true }).click();
  await expect(page).toHaveURL(/auth\/ready/);
});

test('реальные сохранённые замеры текущего бренда с честным состоянием', async ({ page }) => {
  await page.goto(`${origin}/vision/runs`);
  await expect(page.getByRole('heading', { name: 'Замеры', exact: true })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Замеры', exact: true })).toHaveAttribute(
    'href',
    '/vision/runs',
  );
  await expect(page.getByText(alpha).first()).toBeVisible();
  await expect(
    page.getByText('Статусы и результаты не показаны: доступ к источнику не проверен.'),
  ).toBeVisible();
  await expect(page.getByText(beta)).toHaveCount(0);
  await expect(page.getByText('Запуск замеров — скоро')).toBeVisible();
  for (const width of [1440, 1024, 390]) {
    await page.setViewportSize({ width, height: 900 });
    expect(
      await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
    ).toBeTruthy();
    await page.screenshot({
      path: `.local/screenshots/e8-04/measurement-list-${width}.png`,
      fullPage: true,
    });
    for (const theme of ['neutral', 'confectionery', 'pyro']) {
      await page.evaluate((value) => {
        document.documentElement.dataset.theme = value;
      }, theme);
      expect(
        await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
      ).toBeTruthy();
      await page.screenshot({
        path: `.local/screenshots/e8-04/measurement-list-${theme}-${width}.png`,
        fullPage: true,
      });
    }
  }
  await page.addScriptTag({ path: 'node_modules/axe-core/axe.min.js' });
  const violations = await page.evaluate(
    async () =>
      (
        await (
          window as unknown as {
            axe: {
              run: (selector: string, options: object) => Promise<{ violations: { id: string }[] }>;
            };
          }
        ).axe.run('section[aria-labelledby="measurements-title"]', {
          runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'] },
        })
      ).violations,
  );
  expect(violations).toEqual([]);
});

test('задержанный ответ скрывается после переключения бренда в другой вкладке', async ({
  page,
  context,
}) => {
  let captured!: () => void;
  let release!: () => void;
  let finished!: () => void;
  const arrived = new Promise<void>((resolve) => {
    captured = resolve;
  });
  const delayed = new Promise<void>((resolve) => {
    release = resolve;
  });
  const settled = new Promise<void>((resolve) => {
    finished = resolve;
  });
  await page.route('**/vision/runs/data?*', async (route) => {
    const response = await route.fetch();
    captured();
    await delayed;
    await route.fulfill({ response }).catch(() => undefined);
    finished();
  });
  await page.goto(`${origin}/vision/runs`);
  await arrived;
  const other = await context.newPage();
  await other.goto(`${origin}/brands`);
  await other.getByRole('button', { name: 'Открыть кабинет бренда' }).click();
  await expect(other).toHaveURL(/auth\/ready/);
  release();
  await settled;
  await expect(page.getByRole('alert')).toContainText('Контекст бренда изменился');
  await expect(page.getByText(alpha)).toHaveCount(0);
  await other.goto(`${origin}/vision/runs`);
  await expect(other.getByText(beta).first()).toBeVisible();
  await expect(
    other.getByText('Статусы и результаты не показаны: доступ к источнику не проверен.'),
  ).toBeVisible();
  await expect(other.getByText(alpha)).toHaveCount(0);
});

test('ошибка чтения не показывает старые данные и позволяет повторить запрос', async ({ page }) => {
  const path = '**/vision/runs/data?*';
  await page.route(path, (route) => route.abort('failed'));
  await page.goto(`${origin}/vision/runs`);
  await expect(page.getByRole('alert')).toContainText('Не удалось загрузить замеры');
  await expect(page.getByText(alpha)).toHaveCount(0);
  await page.unroute(path);
  await page.getByRole('button', { name: 'Повторить' }).click();
  await expect(page.getByText(alpha).first()).toBeVisible();
});
