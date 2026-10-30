import { expect, test, type Page } from '@playwright/test';
import { createRequire } from 'node:module';
import { mkdir } from 'node:fs/promises';
import path from 'node:path';

const require = createRequire(import.meta.url);
const screenshots = path.join(process.cwd(), '.local/screenshots/preview');
const themes = ['neutral', 'pyro', 'confectionery'] as const;
const widths = [1440, 1024, 390] as const;

async function assertNoOverflow(page: Page) {
  expect(
    await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1),
  ).toBe(true);
}

async function assertAccessible(page: Page) {
  await page.addScriptTag({ path: require.resolve('axe-core/axe.min.js') });
  const violations = await page.evaluate(async () => {
    const axe = (
      window as unknown as {
        axe: {
          run: (
            context: Document,
            options: object,
          ) => Promise<{
            violations: { id: string; impact: string | null; nodes: { target: unknown }[] }[];
          }>;
        };
      }
    ).axe;
    return (
      await axe.run(document, {
        runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21aa'] },
      })
    ).violations.map((item) => ({
      id: item.id,
      impact: item.impact,
      targets: item.nodes.map((node) => node.target),
    }));
  });
  expect(violations).toEqual([]);
}

for (const width of widths) {
  for (const theme of themes) {
    for (const screen of ['login', 'attention'] as const) {
      test(`${screen}: ${theme}, ${width}px`, async ({ page }) => {
        await page.setViewportSize({ width, height: 1000 });
        await page.emulateMedia({ reducedMotion: 'reduce' });
        const errors: string[] = [];
        page.on('pageerror', (error) => errors.push(error.message));
        const response = await page.goto(`/preview/${screen}?theme=${theme}`);
        expect(response?.status()).toBe(200);
        await page.evaluate(() => document.fonts.ready);
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
        await expect(page.locator('html')).toHaveAttribute('data-theme', theme);
        if (screen === 'login') {
          await expect(page.getByLabel('Электронная почта', { exact: true })).toBeVisible();
          await expect(page.getByRole('button', { name: 'Войти', exact: true })).toBeVisible();
        } else if (width < 768) {
          await expect(page.getByRole('button', { name: 'Открыть навигацию' })).toBeVisible();
          await expect(page.getByTestId('attention-card')).toHaveCount(6);
        } else {
          await expect(page.getByRole('table')).toBeVisible();
        }
        await assertNoOverflow(page);
        await assertAccessible(page);
        await mkdir(screenshots, { recursive: true });
        await page.screenshot({
          path: path.join(screenshots, `${screen}-${theme}-${width}.png`),
          fullPage: true,
          animations: 'disabled',
        });
        expect(errors).toEqual([]);
      });
    }
  }
}

for (const state of ['invalid', 'locked', 'mfa', 'loading'] as const) {
  test(`login state: ${state}`, async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 1000 });
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await page.goto(`/preview/login?state=${state}&theme=neutral`);
    await expect(page.getByLabel('Состояние экрана')).toHaveValue(state);
    if (state === 'locked')
      await expect(page.getByRole('button', { name: 'Войти', exact: true })).toBeDisabled();
    if (state === 'invalid')
      await expect(page.getByText('Неверная почта или пароль')).toBeVisible();
    if (state === 'mfa')
      await expect(page.getByLabel('Код подтверждения', { exact: true })).toBeVisible();
    await assertAccessible(page);
    await assertNoOverflow(page);
    await mkdir(screenshots, { recursive: true });
    await page.screenshot({
      path: path.join(screenshots, `login-${state}-1440.png`),
      fullPage: true,
      animations: 'disabled',
    });
  });
}

for (const state of ['empty', 'loading', 'error'] as const) {
  test(`attention state: ${state}`, async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 1000 });
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await page.goto(`/preview/attention?state=${state}&theme=pyro`);
    await expect(page.getByLabel('Состояние экрана')).toHaveValue(state);
    if (state === 'empty')
      await expect(
        page.getByRole('heading', { name: 'Всё в порядке', exact: true }).first(),
      ).toBeVisible();
    if (state === 'loading')
      await expect(page.getByRole('status')).toHaveAttribute('aria-busy', 'true');
    if (state === 'error')
      await expect(page.getByRole('button', { name: 'Повторить', exact: true })).toBeVisible();
    await assertAccessible(page);
    await assertNoOverflow(page);
    await mkdir(screenshots, { recursive: true });
    await page.screenshot({
      path: path.join(screenshots, `attention-${state}-1440.png`),
      fullPage: true,
      animations: 'disabled',
    });
  });
}

test('filters, local snooze and draft work without mutations or browser storage', async ({
  page,
}) => {
  const mutations: string[] = [];
  page.on('request', (request) => {
    if (!['GET', 'HEAD'].includes(request.method())) mutations.push(request.url());
  });
  await page.goto('/preview/attention');
  const table = page.getByRole('table');
  await page.getByLabel('Модуль', { exact: true }).selectOption('vision');
  await expect(table.getByTestId('attention-row')).toHaveCount(1);
  await page.getByRole('button', { name: 'Сбросить', exact: true }).click();
  await page.getByLabel('Поиск по событиям', { exact: true }).fill('приглашение');
  await expect(table.getByTestId('attention-row')).toHaveCount(1);
  await page.getByRole('button', { name: 'Сбросить', exact: true }).click();
  await table
    .getByRole('button', { name: 'Действия: Диалоги ждут менеджера', exact: true })
    .click();
  await table.getByRole('button', { name: 'Отложить на 7 дней', exact: true }).click();
  await expect(table.getByTestId('attention-row')).toHaveCount(5);
  await table.getByRole('button', { name: 'Открыть: Неизвестные вопросы', exact: true }).click();
  const dialog = page.getByRole('dialog');
  await expect(dialog).toBeVisible();
  await dialog.getByLabel('Локальный черновик заметки').fill('Тестовый локальный черновик');
  await dialog.getByRole('button', { name: 'Сохранить черновик', exact: true }).click();
  await expect(dialog).not.toBeVisible();
  await table.getByRole('button', { name: 'Открыть: Неизвестные вопросы', exact: true }).click();
  await expect(page.getByLabel('Локальный черновик заметки')).toHaveValue(
    'Тестовый локальный черновик',
  );
  await page.keyboard.press('Escape');
  await expect(
    table.getByRole('button', { name: 'Открыть: Неизвестные вопросы', exact: true }),
  ).toBeFocused();
  expect(
    await page.evaluate(() => ({ local: localStorage.length, session: sessionStorage.length })),
  ).toEqual({ local: 0, session: 0 });
  expect(mutations).toEqual([]);
  await page.reload();
  await expect(page.getByRole('table').getByTestId('attention-row')).toHaveCount(6);
});

test('mobile navigation traps focus, closes with Escape and returns to its trigger', async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/preview/attention');
  const trigger = page.getByRole('button', { name: 'Открыть навигацию' });
  await trigger.click();
  const dialog = page.getByRole('dialog');
  await expect(dialog).toBeVisible();
  await expect(dialog.getByRole('navigation', { name: 'Мобильная навигация' })).toBeVisible();
  await page.keyboard.press('Tab');
  expect(
    await page.evaluate(() =>
      document.querySelector('dialog[open]')?.contains(document.activeElement),
    ),
  ).toBe(true);
  await page.keyboard.press('Escape');
  await expect(dialog).not.toBeVisible();
  await expect(trigger).toBeFocused();
});

test('wide layout fills the available width', async ({ page }) => {
  await page.setViewportSize({ width: 1920, height: 1080 });
  await page.goto('/preview/attention');
  await assertNoOverflow(page);
  const content = await page.locator('#preview-main').boundingBox();
  expect(content?.width).toBeGreaterThan(1600);
});

test('popup dismissal restores focus and both search fields reset together', async ({ page }) => {
  await page.goto('/preview/attention');
  const table = page.getByRole('table');
  const trigger = table.getByRole('button', {
    name: 'Действия: Диалоги ждут менеджера',
    exact: true,
  });
  await trigger.click();
  await table.getByRole('button', { name: 'Отложить на 7 дней', exact: true }).focus();
  await page.keyboard.press('Escape');
  await expect(trigger).toBeFocused();
  const mode = page.getByRole('button', { name: 'Сайт · Автономно', exact: true });
  await mode.click();
  await page.getByRole('button', { name: 'С подсказками', exact: true }).focus();
  await page.keyboard.press('Enter');
  await expect(
    page.getByRole('button', { name: 'Сайт · С подсказками', exact: true }),
  ).toBeFocused();
  const globalSearch = page.getByLabel('Глобальный поиск по демоданным', { exact: true });
  await globalSearch.fill('диалоги');
  await globalSearch.press('Enter');
  await expect(table.getByTestId('attention-row')).toHaveCount(1);
  await page.getByRole('button', { name: 'Сбросить', exact: true }).click();
  await expect(globalSearch).toHaveValue('');
  await expect(page.getByLabel('Поиск по событиям', { exact: true })).toHaveValue('');
  await trigger.click();
  await table.getByRole('button', { name: 'Отложить на 7 дней', exact: true }).click();
  await expect(
    table.getByRole('button', { name: 'Действия: Неизвестные вопросы', exact: true }),
  ).toBeFocused();
});
