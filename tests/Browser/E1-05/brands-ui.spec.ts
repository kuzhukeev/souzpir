import { expect, test, type Page } from '@playwright/test';
const url =
  process.env.BRANDS_FIXTURE_URL || 'http://127.0.0.1:5215/tests/Browser/E1-05/ui-fixture.html';
test.use({
  launchOptions: process.env.BRANDS_CHROMIUM_PATH
    ? { executablePath: process.env.BRANDS_CHROMIUM_PATH }
    : {},
});
test.beforeEach(async ({ page }) => {
  await page.route('**/*', (route) => {
    const origin = new URL(route.request().url()).origin;
    return origin === 'http://127.0.0.1:5215' ? route.continue() : route.abort('blockedbyclient');
  });
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
      .map((node) => node.outerHTML.slice(0, 120)),
  );
  expect(small).toEqual([]);
}
for (const width of [1440, 1024, 390]) {
  for (const theme of ['neutral', 'pyro', 'confectionery']) {
    test(theme + ' settings fit ' + width, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(url + '?theme=' + theme);
      await expect(
        page.getByRole('heading', { level: 1, name: 'Пиротехника — Северные огни' }),
      ).toBeVisible();
      await readable(page);
      await page.screenshot({
        path: '.local/screenshots/e1-05/general-' + theme + '-' + width + '.png',
        fullPage: true,
      });
      await page.getByRole('tab', { name: 'Оформление кабинета', exact: true }).click();
      await expect(page.getByRole('radio')).toHaveCount(3);
      await expect(
        page.getByRole('region', { name: 'Предпросмотр сайдбара и шапки' }),
      ).toHaveAttribute('data-theme', theme);
      await readable(page);
      await page.screenshot({
        path: '.local/screenshots/e1-05/theme-' + theme + '-' + width + '.png',
        fullPage: true,
      });
      await page.getByRole('tab', { name: 'Домены', exact: true }).click();
      await expect(page.getByText('Ожидает проверки')).toBeVisible();
      await expect(page.getByText('Проверка не пройдена')).toBeVisible();
      await expect(page.getByRole('alert')).toContainText('Проверочный файл не найден');
      await readable(page);
      await page.screenshot({
        path: '.local/screenshots/e1-05/domains-' + theme + '-' + width + '.png',
        fullPage: true,
      });
      await page.getByRole('tab', { name: 'Модули', exact: true }).click();
      await expect(page.getByRole('switch')).toHaveCount(2);
      await readable(page);
      await page.getByRole('tab', { name: 'Архив', exact: true }).click();
      await expect(page.getByRole('button', { name: 'Архивировать бренд' })).toBeVisible();
      await readable(page);
    });
  }
}
test('all tabs pass axe WCAG and controls retain keyboard access', async ({ page }) => {
  await page.goto(url);
  await page.addScriptTag({ path: 'node_modules/axe-core/axe.min.js' });
  for (const tab of ['Основное', 'Оформление кабинета', 'Домены', 'Модули', 'Архив']) {
    await page.getByRole('tab', { name: tab, exact: true }).click();
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
  await page.getByRole('tab', { name: 'Основное', exact: true }).focus();
  await page.keyboard.press('ArrowRight');
  await expect(page.getByRole('tab', { name: 'Оформление кабинета', exact: true })).toHaveAttribute(
    'aria-selected',
    'true',
  );
  await page.getByRole('radio', { name: /^Пиротехника/ }).focus();
  await page.keyboard.press('ArrowRight');
  await expect(page.getByRole('region', { name: 'Предпросмотр сайдбара и шапки' })).toHaveAttribute(
    'data-theme',
    'confectionery',
  );
});
test('aliases, domain instructions, styled select and empty state', async ({ page }) => {
  await page.goto(url);
  await page.getByRole('textbox', { name: 'Добавить алиас', exact: true }).fill('Праздник');
  await page.getByRole('textbox', { name: 'Добавить алиас', exact: true }).press('Enter');
  await expect(
    page.getByRole('button', { name: 'Удалить алиас Праздник', exact: true }),
  ).toBeVisible();
  await page.getByRole('button', { name: 'Удалить алиас Праздник', exact: true }).click();
  await page.getByRole('tab', { name: 'Домены', exact: true }).click();
  await page.getByRole('button', { name: 'Инструкция', exact: true }).first().click();
  await expect(
    page.getByRole('dialog', { name: 'Подтверждение домена', exact: true }),
  ).toContainText('/.well-known/eyon-domain-verification.txt');
  await page.keyboard.press('Escape');
  await page.getByRole('button', { name: 'Добавить домен', exact: true }).click();
  await page.getByRole('combobox', { name: 'Способ подтверждения', exact: true }).click();
  await expect(page.getByRole('option', { name: 'Файл на сайте', exact: true })).toBeFocused();
  await page.keyboard.press('ArrowDown');
  await expect(page.getByRole('option', { name: 'DNS TXT-запись', exact: true })).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(
    page.getByRole('combobox', { name: 'Способ подтверждения', exact: true }),
  ).toContainText('DNS TXT-запись');
  await page.keyboard.press('Escape');
  await page.goto(url + '?empty=1');
  await page.getByRole('tab', { name: 'Домены', exact: true }).click();
  await expect(
    page.getByRole('heading', { name: 'Домены ещё не добавлены', exact: true }),
  ).toBeVisible();
  await page.screenshot({ path: '.local/screenshots/e1-05/domains-empty.png', fullPage: true });
});
test('network failure keeps the edited form and shows an actionable error', async ({ page }) => {
  await page.goto(url);
  await page.route('**/settings/general', (route) => route.abort('failed'));
  await page
    .getByRole('textbox', { name: 'Название бренда', exact: true })
    .fill('Изменённое название');
  await page.getByRole('button', { name: 'Сохранить изменения', exact: true }).click();
  await expect(page.getByRole('alert')).toContainText('Не удалось связаться с сервером');
  await expect(page.getByRole('textbox', { name: 'Название бренда', exact: true })).toHaveValue(
    'Изменённое название',
  );
  await page.screenshot({
    path: '.local/screenshots/e1-05/general-network-error.png',
    fullPage: true,
  });
});

test('the three presets change the rendered brand palette without replacing common control colors', async ({
  page,
}) => {
  await page.goto(url);
  await page.getByRole('tab', { name: 'Оформление кабинета', exact: true }).click();
  const backgrounds = [];
  const accents = [];
  for (const name of [/^Пиротехника/, /^Кондитерская/, /^Нейтральная/]) {
    await page.getByRole('radio', { name }).check();
    const colors = await page.locator('[data-brand-preview-header]').evaluate((element) => {
      const style = getComputedStyle(element);
      return { background: style.backgroundColor, accent: style.borderBottomColor };
    });
    backgrounds.push(colors.background);
    accents.push(colors.accent);
  }
  expect(new Set(backgrounds).size).toBe(3);
  expect(new Set(accents).size).toBe(3);
});

test('quota and archive states are readable at desktop and mobile sizes', async ({ page }) => {
  for (const width of [1440, 390]) {
    await page.setViewportSize({ width, height: 900 });
    await page.goto(url);
    await page.getByRole('tab', { name: 'Модули', exact: true }).click();
    await expect(page.getByText('Диалоги за месяц: — / —', { exact: true })).toBeVisible();
    await expect(page.getByText('Замеры за неделю: — / —', { exact: true })).toBeVisible();
    await expect(page.getByText('Лимит не задан', { exact: true })).toHaveCount(2);
    await readable(page);
    await page.screenshot({
      path: '.local/screenshots/e1-05/modules-neutral-' + width + '.png',
      fullPage: true,
    });
    await page.getByRole('tab', { name: 'Архив', exact: true }).click();
    await page.screenshot({
      path: '.local/screenshots/e1-05/archive-neutral-' + width + '.png',
      fullPage: true,
    });
    await page.getByRole('button', { name: 'Архивировать бренд', exact: true }).click();
    await expect(
      page.getByRole('dialog', { name: 'Архивировать бренд', exact: true }),
    ).toBeVisible();
    await readable(page);
    await page.screenshot({
      path: '.local/screenshots/e1-05/archive-confirm-neutral-' + width + '.png',
      fullPage: true,
    });
    await page.keyboard.press('Escape');
    await expect(
      page.getByRole('button', { name: 'Архивировать бренд', exact: true }),
    ).toBeFocused();
  }
});

test('external checks show the disabled state unless explicitly enabled', async ({ page }) => {
  await page.goto(url);
  await expect(page.getByText('Внешние проверки пока недоступны.')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Проверить', exact: true })).toBeDisabled();
  await page.screenshot({
    path: '.local/screenshots/e1-05/network-disabled-general.png',
    fullPage: true,
  });
  await page.getByRole('tab', { name: 'Домены', exact: true }).click();
  await expect(page.getByText('Внешние проверки пока недоступны.')).toBeVisible();
  for (const button of await page.getByRole('button', { name: 'Проверить', exact: true }).all()) {
    await expect(button).toBeDisabled();
  }
  await page.screenshot({
    path: '.local/screenshots/e1-05/network-disabled-domains.png',
    fullPage: true,
  });
  await page.goto(url + '?checks=1');
  await expect(page.getByRole('button', { name: 'Проверить', exact: true })).toBeEnabled();
  await page.getByRole('tab', { name: 'Домены', exact: true }).click();
  for (const button of await page.getByRole('button', { name: 'Проверить', exact: true }).all()) {
    await expect(button).toBeEnabled();
  }
});
