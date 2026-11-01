import { execFileSync } from 'node:child_process';
import { expect, test } from '@playwright/test';

test.skip(
  process.env.RUN_JOURNAL_B_E2E !== '1' || !process.env.TEAM_PHP_PATH,
  'Requires the isolated Journal test environment',
);
test.use({
  launchOptions: process.env.TEAM_CHROMIUM_PATH
    ? { executablePath: process.env.TEAM_CHROMIUM_PATH }
    : {},
});
const origin = 'http://127.0.0.1:8229';
let knownActor: string;
let tag: string;

test.beforeAll(() => {
  const options = { encoding: 'utf8' as const, env: { ...process.env, APP_ENV: 'testing' } };
  execFileSync(process.env.TEAM_PHP_PATH!, ['tests/Browser/E1-06/prepare-fixture.php'], options);
  const result = execFileSync(
    process.env.TEAM_PHP_PATH!,
    ['tests/Browser/E1-06/prepare-fixture.php'],
    {
      ...options,
      env: { ...options.env, E106_ACTIVITY_FIXTURE: '1' },
    },
  );
  const fixture = JSON.parse(result) as { actor: string; tag: string };
  knownActor = fixture.actor;
  tag = fixture.tag;
});

test.beforeEach(async ({ page }) => {
  await page.route('**/*', (route) =>
    new URL(route.request().url()).origin === origin
      ? route.continue()
      : route.abort('blockedbyclient'),
  );
});

test('переход из карточки сохраняет пустой фильтр видимым, а сброс возвращает доступные события', async ({
  page,
}) => {
  await page.goto(`${origin}/login`);
  await page.getByLabel('Рабочая почта').fill('team-e106-owner@example.test');
  await page.getByLabel('Пароль', { exact: true }).fill('test-only-password-123');
  await page.getByRole('button', { name: 'Войти', exact: true }).click();
  await expect(page).toHaveURL(/auth\/ready/);
  await page.goto(`${origin}/team`);
  await page
    .getByRole('region', { name: 'Таблица участников' })
    .getByRole('link', { name: 'Открыть карточку: Тестовый отключённый' })
    .click();
  const link = page.getByRole('link', { name: 'Посмотреть активность участника' });
  const target = await link.getAttribute('href');
  expect(target).toMatch(/^\/journal\?actor=[0-9A-HJKMNP-TV-Z]{26}$/i);
  await link.click();
  await expect(page).toHaveURL(`${origin}${target}`);
  const filter = page.getByRole('combobox', { name: 'Участник' });
  await expect(filter).toContainText('Выбранный участник');
  await expect(page.getByText('Действий пока нет')).toBeVisible();
  await expect(page.getByText(`activity-target-${tag}`)).toHaveCount(0);
  await filter.click();
  await expect(page.getByRole('option', { name: 'Выбранный участник' })).toHaveCount(1);
  await page.keyboard.press('Escape');
  for (const width of [1440, 1024, 390]) {
    await page.setViewportSize({ width, height: 900 });
    expect(
      await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
    ).toBeTruthy();
    await expect(filter).toContainText('Выбранный участник');
    await page.screenshot({
      path: `.local/screenshots/e1-09b/selected-actor-${width}.png`,
      fullPage: true,
    });
  }
  for (const theme of ['neutral', 'pyro', 'confectionery']) {
    await page.evaluate((value) => {
      document.documentElement.dataset.theme = value;
    }, theme);
    await expect(filter).toContainText('Выбранный участник');
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
    await page.screenshot({
      path: `.local/screenshots/e1-09b/selected-actor-${theme}-390.png`,
      fullPage: true,
    });
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
  await page.getByRole('button', { name: 'Сбросить' }).click();
  await expect(page).toHaveURL(`${origin}/journal`);
  await expect(page.getByRole('combobox', { name: 'Участник' })).toContainText('Все');
  await expect(page.getByText(`activity-target-${tag}`).last()).toBeVisible();
  await expect(page.getByText(`activity-other-brand-${tag}`)).toHaveCount(0);

  await page.goto(`${origin}/journal?actor=${knownActor}`);
  await expect(page.getByRole('combobox', { name: 'Участник' })).toContainText(
    'Тестовый наблюдатель',
  );
  await expect(page.getByText(`activity-target-${tag}`).last()).toBeVisible();
});

test('наблюдателю запрещён журнал и известный или неизвестный фильтр не расширяет доступ', async ({
  page,
}) => {
  await page.goto(`${origin}/login`);
  await page.getByLabel('Рабочая почта').fill('team-e106-viewer@example.test');
  await page.getByLabel('Пароль', { exact: true }).fill('test-only-password-123');
  await page.getByRole('button', { name: 'Войти', exact: true }).click();
  await expect(page).toHaveURL(/auth\/ready/);
  expect((await page.request.get(`${origin}/journal?actor=${knownActor}`)).status()).toBe(403);
  expect(
    (await page.request.get(`${origin}/journal?actor=01J00000000000000000000009`)).status(),
  ).toBe(403);
});
