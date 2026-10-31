import { expect, test } from '@playwright/test';
import axe from 'axe-core';
const base = 'http://127.0.0.1:5248/tests/Browser/E8-02/ui-fixture.html';
test.use({
  launchOptions: { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH },
});
for (const theme of ['neutral', 'confectionery', 'pyro'])
  for (const width of [1440, 1024, 390]) {
    test(`${theme} ${width}: responsive and accessible fields`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 });
      await page.route('**/*', (route) =>
        new URL(route.request().url()).origin === 'http://127.0.0.1:5248'
          ? route.continue()
          : route.abort(),
      );
      await page.goto(`${base}?theme=${theme}&step=1`);
      await expect(page.getByLabel('Название бренда')).toBeVisible();
      expect(
        await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
      ).toBeTruthy();
      await page.addScriptTag({ content: axe.source });
      const results = await page.evaluate(
        async () =>
          (
            await (window as unknown as { axe: typeof axe }).axe.run(document, {
              runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa'] },
            })
          ).violations,
      );
      expect(results).toEqual([]);
      await page.screenshot({
        path: `.local/screenshots/e8-02/fields-${theme}-${width}.png`,
        fullPage: true,
      });
    });
  }
test('limits competitors, facts, questions in browser without API calls', async ({ page }) => {
  await page.goto(`${base}?step=4`);
  for (let i = 0; i < 10; i++)
    await page.getByRole('button', { name: 'Добавить конкурента' }).click();
  await expect(page.getByRole('button', { name: 'Добавить конкурента' })).toBeDisabled();
  await page.getByRole('button', { name: 'Шаг 5' }).click();
  for (let i = 0; i < 10; i++) {
    await page.getByLabel('Добавить: Дополнительные факты').fill(`Факт ${i}`);
    await page.getByRole('button', { name: 'Добавить в Дополнительные факты' }).click();
  }
  await expect(
    page.getByRole('button', { name: 'Добавить в Дополнительные факты' }),
  ).toBeDisabled();
  await page.getByRole('button', { name: 'Шаг 7' }).click();
  for (let i = 0; i < 15; i++) {
    await page.getByLabel('Добавить: Вопросы клиентов').fill(`Вопрос ${i}`);
    await page.getByRole('button', { name: 'Добавить в Вопросы клиентов' }).click();
  }
  await expect(page.getByRole('button', { name: 'Добавить в Вопросы клиентов' })).toBeDisabled();
});

test('all seven field sets are usable and accessible at 390 px', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 900 });
  await page.route('**/*', (route) =>
    new URL(route.request().url()).origin === 'http://127.0.0.1:5248'
      ? route.continue()
      : route.abort(),
  );
  await page.goto(`${base}?theme=neutral`);
  await page.addScriptTag({ content: axe.source });
  for (let step = 1; step <= 7; step++) {
    await page.getByRole('button', { name: `Шаг ${step}`, exact: true }).click();
    expect(
      await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
    ).toBeTruthy();
    const violations = await page.evaluate(
      async () =>
        (
          await (window as unknown as { axe: typeof axe }).axe.run(document, {
            runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa'] },
          })
        ).violations,
    );
    expect(violations, `step ${step}`).toEqual([]);
    await page.screenshot({
      path: `.local/screenshots/e8-02/fields-step-${step}-390.png`,
      fullPage: true,
    });
  }
});

test('zero configured limits and validation field association', async ({ page }) => {
  await page.goto(`${base}?step=4&competitors=0&facts=0&questions=0&error=1`);
  await expect(page.getByRole('button', { name: 'Добавить конкурента' })).toBeDisabled();
  await page.getByRole('button', { name: 'Шаг 5' }).click();
  await page.getByLabel('Добавить: Дополнительные факты').fill('Факт');
  await expect(
    page.getByRole('button', { name: 'Добавить в Дополнительные факты' }),
  ).toBeDisabled();
  await page.getByRole('button', { name: 'Шаг 7' }).click();
  await page.getByLabel('Добавить: Вопросы клиентов').fill('Вопрос');
  await expect(page.getByRole('button', { name: 'Добавить в Вопросы клиентов' })).toBeDisabled();
  await page.getByRole('button', { name: 'Шаг 1' }).click();
  await expect(page.getByLabel('Сайт', { exact: true })).toHaveAttribute('aria-invalid', 'true');
  await expect(page.getByLabel('Сайт', { exact: true })).toHaveAccessibleDescription(
    'Введите адрес сайта',
  );
});
test('custom platform preserves entered name and address', async ({ page }) => {
  await page.goto(`${base}?step=6`);
  await page.getByRole('button', { name: 'Добавить другую площадку' }).click();
  await page.getByLabel('Название площадки 1').fill('Мой каталог');
  await page.getByLabel('Ссылка площадки 1').fill('https://example.test/company');
  await expect(page.getByLabel('Название площадки 1')).toHaveValue('Мой каталог');
  await expect(page.getByLabel('Ссылка площадки 1')).toHaveValue('https://example.test/company');
});
