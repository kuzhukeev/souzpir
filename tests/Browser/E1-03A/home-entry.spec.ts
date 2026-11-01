import { expect, test, type Page } from '@playwright/test';

test.skip(
  process.env.RUN_HOME_ENTRY_E2E !== '1',
  'Requires the isolated HomeEntry server and synthetic fixture',
);
test.use({
  launchOptions: process.env.HOME_CHROMIUM_PATH
    ? { executablePath: process.env.HOME_CHROMIUM_PATH }
    : {},
});
test.setTimeout(90_000);
const origin = 'http://127.0.0.1:8266';
async function login(page: Page, role: 'owner' | 'viewer') {
  await page.goto(`${origin}/login`);
  await page.getByLabel('Рабочая почта').fill(`home-${role}@example.test`);
  await page.getByLabel('Пароль', { exact: true }).fill('test-only-password-123');
  await page.getByRole('button', { name: 'Войти', exact: true }).click();
  await expect(page).toHaveURL(/(?:auth\/ready|\/dashboard)$/);
}
test.beforeEach(async ({ context }) => {
  await context.route('**/*', (route) =>
    new URL(route.request().url()).origin === origin
      ? route.continue()
      : route.abort('blockedbyclient'),
  );
});

test('реальный вход, главная в трёх темах и изоляция бренда наблюдателя', async ({
  page,
  browser,
}) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(`${origin}/dashboard`);
  await expect(page).toHaveURL(/\/login/);
  await login(page, 'owner');
  await page.goto(`${origin}/company`);
  const homeLink = page
    .getByRole('navigation', { name: 'Основная навигация' })
    .getByRole('link', { name: 'Главная', exact: true });
  await expect(homeLink).toHaveAttribute('href', '/dashboard');
  await homeLink.click();
  await expect(page).toHaveURL(`${origin}/dashboard`);
  await expect(page.getByRole('heading', { name: 'Главная', level: 1 })).toBeVisible();
  const section = page.getByRole('region', { name: 'Разделы кабинета' });
  await expect(section.getByRole('link', { name: /Команда/ })).toHaveAttribute('href', '/team');
  await expect(section.getByRole('link', { name: /Журнал/ })).toHaveAttribute('href', '/journal');
  await expect(page.getByText('Показатели для главной страницы пока недоступны.')).toBeVisible();
  const props = await page.evaluate(
    () =>
      JSON.parse(document.querySelector('script[data-page="app"]')?.textContent || '{}').props as {
        brand: { id: string };
        brands: { id: string; name: string }[];
      },
  );
  const current = props.brands.find((brand) => brand.name === 'Альфа — Пиротехника')!;
  const other = props.brands.find((brand) => brand.name === 'Бета — Кондитерская')!;
  expect(props.brand.id).toBe(current.id);
  const themes = [
    { name: 'Альфа — Пиротехника', theme: 'pyro' },
    { name: 'Бета — Кондитерская', theme: 'confectionery' },
    { name: 'Вектор — нейтральный бренд', theme: 'neutral' },
  ];
  for (const [index, brand] of themes.entries()) {
    if (index) {
      await page.getByRole('combobox', { name: 'Выбрать бренд' }).click();
      await page.getByRole('option', { name: brand.name }).click();
      await expect(page).toHaveURL(/auth\/ready/);
      await page.goto(`${origin}/dashboard`);
    }
    await expect(page.locator('html')).toHaveAttribute('data-theme', brand.theme);
    await expect(
      page.getByRole('heading', { level: 1, name: 'Главная' }).locator('..'),
    ).toContainText(brand.name);
    for (const width of [1440, 1024, 390]) {
      await page.setViewportSize({ width, height: 900 });
      expect(
        await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
      ).toBeTruthy();
      const small = await page.locator('body').evaluate((body) =>
        Array.from(body.querySelectorAll('*'))
          .filter((node) => {
            const style = getComputedStyle(node);
            const box = node.getBoundingClientRect();
            return (
              Array.from(node.childNodes).some(
                (child) => child.nodeType === Node.TEXT_NODE && child.textContent?.trim(),
              ) &&
              style.display !== 'none' &&
              style.visibility !== 'hidden' &&
              box.width > 0 &&
              box.height > 0 &&
              parseFloat(style.fontSize) < 13
            );
          })
          .map((node) => node.outerHTML.slice(0, 100)),
      );
      expect(small).toEqual([]);
      await page.screenshot({
        path: `.local/screenshots/e1-03a/home-${brand.theme}-${width}.png`,
        fullPage: true,
      });
    }
    await page.setViewportSize({ width: 1440, height: 900 });
  }
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
  const first = section.getByRole('link', { name: /Компания и бренды/ });
  await first.focus();
  await expect(first).toBeFocused();
  await expect(first).toHaveCSS('cursor', 'pointer');

  const viewerContext = await browser.newContext();
  try {
    await viewerContext.route('**/*', (route) =>
      new URL(route.request().url()).origin === origin
        ? route.continue()
        : route.abort('blockedbyclient'),
    );
    const viewer = await viewerContext.newPage();
    await login(viewer, 'viewer');
    await viewer.goto(`${origin}/dashboard`);
    const viewerSections = viewer.getByRole('region', { name: 'Разделы кабинета' });
    await expect(viewerSections.getByRole('link', { name: /Команда/ })).toBeVisible();
    await expect(viewerSections.getByRole('link', { name: /Журнал/ })).toHaveCount(0);
    await expect(viewerSections.getByRole('link', { name: /Компания и бренды/ })).toHaveCount(0);
    const viewerProps = await viewer.evaluate(
      () =>
        JSON.parse(document.querySelector('script[data-page="app"]')?.textContent || '{}')
          .props as { brand: { id: string }; brands: { id: string }[] },
    );
    expect(viewerProps.brand.id).toBe(current.id);
    expect(viewerProps.brands.map((item) => item.id)).not.toContain(other.id);
    expect(
      (
        await viewer.request.get(`${origin}/dashboard`, { headers: { 'X-Brand-Id': other.id } })
      ).status(),
    ).toBe(404);
  } finally {
    await viewerContext.close();
  }
});
