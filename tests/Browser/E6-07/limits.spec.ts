import { expect, test } from '@playwright/test';
import { createRequire } from 'node:module';
import { mkdir } from 'node:fs/promises';
import path from 'node:path';

test.use({
  launchOptions: process.env.LIMITS_CHROMIUM_PATH
    ? { executablePath: process.env.LIMITS_CHROMIUM_PATH }
    : {},
});
test.skip(
  process.env.RUN_LIMITS_E2E !== '1',
  'Requires an isolated limits test server and fixture',
);
test.setTimeout(90000);
const base = process.env.LIMITS_BASE_URL || 'http://127.0.0.1:8106';
const require = createRequire(import.meta.url);

test('real counters and brand delivery settings persist at all supported widths', async ({
  page,
}) => {
  await page.goto(base + '/login');
  await page.getByLabel('Рабочая почта').fill('seller-limits-e607@example.test');
  await page.getByLabel('Пароль', { exact: true }).fill('test-only-password-123');
  await page.getByRole('button', { name: 'Войти', exact: true }).click();
  await expect(page).toHaveURL(/\/auth\/ready$/);
  await page.goto(base + '/seller/settings/limits');
  await expect(page.getByRole('heading', { name: 'Лимиты модуля' })).toBeVisible();
  await expect(page.getByText('Диалоги за месяц')).toBeVisible();
  await expect(page.getByText('лимит не задан').first()).toBeVisible();
  await expect(page.getByText('USD')).toHaveCount(0);
  const shots = path.join(process.cwd(), '.local/screenshots/e6-07');
  await mkdir(shots, { recursive: true });
  for (const width of [1440, 1024, 390]) {
    await page.setViewportSize({ width, height: 900 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(
      true,
    );
    await page.addScriptTag({ path: require.resolve('axe-core/axe.min.js') });
    const violations = await page.evaluate(async () =>
      (
        await (
          window as unknown as {
            axe: {
              run: (root: Document, options: object) => Promise<{ violations: { id: string }[] }>;
            };
          }
        ).axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21aa'] } })
      ).violations.map((item) => item.id),
    );
    expect(violations).toEqual([]);
    await page.screenshot({
      path: path.join(shots, 'limits-' + width + '.png'),
      fullPage: true,
      animations: 'disabled',
    });
  }
  await page.getByRole('navigation', { name: 'Настройки продавца' }).getByRole('link', { name: 'Уведомления', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Уведомления модуля' })).toBeVisible();
  const event = page.getByRole('group', { name: 'Каналы: Диалог ждёт менеджера' });
  await expect(event.getByLabel('Telegram')).toBeEnabled();
  await event.getByLabel('Кабинет').uncheck();
  await page.getByLabel('Когда', { exact: true }).first().click();
  await page.getByRole('option', { name: 'Сводкой' }).click();
  await page.getByRole('button', { name: 'Сохранить изменения' }).click();
  await expect(page.getByRole('status').last()).toHaveText('Настройки сохранены.');
  await page.reload();
  await expect(event.getByLabel('Почта')).toBeChecked();
  await expect(event.getByLabel('Кабинет')).not.toBeChecked();
  await page.getByLabel('Событие для теста').click();
  await page.getByRole('option', { name: 'Обещание просрочено' }).click();
  await page.getByRole('button', { name: 'Отправить тест себе' }).click();
  await expect(page.getByText('Кабинет: доставлен')).toBeVisible();
  await page.getByRole('link', { name: 'Мои сводки' }).click();
  await expect(page.getByText('Тест уведомлений продавца')).toBeVisible();
  await page.getByLabel('Моё время сводки').fill('13:45');
  await page.getByRole('button', { name: 'Сохранить время' }).click();
  await expect(page.getByRole('status')).toHaveText('Время сводки сохранено.');
  await page.reload();
  await expect(page.getByLabel('Моё время сводки')).toHaveValue('13:45');
  for (const width of [1440, 1024, 390]) {
    await page.setViewportSize({ width, height: 900 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
    await page.addScriptTag({ path: require.resolve('axe-core/axe.min.js') });
    const violations = await page.evaluate(async () => (await (window as unknown as { axe: { run: (root: Document, options: object) => Promise<{ violations: { id: string }[] }> } }).axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21aa'] } })).violations.map((item) => item.id));
    expect(violations).toEqual([]);
    await page.screenshot({ path: path.join(shots, 'digest-time-' + width + '.png'), fullPage: true, animations: 'disabled' });
  }
  await page.goBack();
  for (const width of [1440, 1024, 390]) {
    await page.setViewportSize({ width, height: 900 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
    await page.addScriptTag({ path: require.resolve('axe-core/axe.min.js') });
    const violations = await page.evaluate(async () => (await (window as unknown as { axe: { run: (root: Document, options: object) => Promise<{ violations: { id: string }[] }> } }).axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21aa'] } })).violations.map((item) => item.id));
    expect(violations).toEqual([]);
    await page.screenshot({ path: path.join(shots, 'notifications-' + width + '.png'), fullPage: true, animations: 'disabled' });
  }
});
