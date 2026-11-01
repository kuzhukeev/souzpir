import { mkdir } from 'node:fs/promises';
import { expect, test, type Page } from '@playwright/test';

const origin = 'http://127.0.0.1:18110';
const assets = 'http://127.0.0.1:5237';
test.skip(process.env.RUN_E110_ACCESS_BROWSER !== '1', 'Requires the isolated E1-10 browser fixture.');
test.use({
  trace: 'off', screenshot: 'off', video: 'off',
  launchOptions: { executablePath: process.env.E110_CHROMIUM_PATH },
});

async function login(page: Page, email: string) {
  await page.route('**/*', (route) =>
    [origin, assets].includes(new URL(route.request().url()).origin)
      ? route.continue()
      : route.abort('blockedbyclient'),
  );
  await page.goto(`${origin}/login`);
  await page.getByLabel('Рабочая почта').fill(email);
  await page.getByLabel('Пароль', { exact: true }).fill('test-only-password-123');
  await page.getByRole('button', { name: 'Войти', exact: true }).click();
  await expect(page).toHaveURL(/auth\/ready/);
  await page.goto(`${origin}/integrations`);
}

test('наблюдатель видит статусы и объяснение вместо недоступной настройки', async ({ page }) => {
  await login(page, 'viewer-e110@example.test');
  for (const title of ['API и вебхуки', 'Уведомления компании']) {
    const card = page.locator('article').filter({ has: page.getByRole('heading', { name: title, exact: true }) });
    await expect(card.getByText('Нет прав на настройку')).toBeVisible();
    await expect(card.getByRole('link', { name: 'Настроить' })).toHaveCount(0);
    await expect(card.getByText('Не подключён', { exact: true })).toBeVisible();
  }
  await page.addScriptTag({ path: 'node_modules/axe-core/axe.min.js' });
  for (const theme of ['neutral', 'pyro', 'confectionery']) {
    await page.evaluate((value) => { document.documentElement.dataset.theme = value; }, theme);
    for (const width of [390, 1024, 1440]) {
      await page.setViewportSize({ width, height: 900 });
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
      const label = page.getByText('Нет прав на настройку').first();
      expect(await label.evaluate((node) => parseFloat(getComputedStyle(node).fontSize))).toBeGreaterThanOrEqual(13);
      if (width === 390) {
        const heading = page.getByRole('heading', { name: 'Уведомления компании', exact: true });
        expect((await heading.boundingBox())?.width).toBeGreaterThan(160);
      }
    }
    const violations = await page.evaluate(async () => (
      await (window as unknown as { axe: { run: (root: Document, options: object) => Promise<{ violations: { id: string }[] }> } })
        .axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa'] } })
    ).violations);
    expect(violations).toEqual([]);
  }
  await page.setViewportSize({ width: 390, height: 900 });
  await mkdir('.local/screenshots/e1-10', { recursive: true });
  await page.screenshot({ path: '.local/screenshots/e1-10/connection-viewer-access-390.png', fullPage: true });
});

test('владелец открывает обе страницы настроек', async ({ page }) => {
  await login(page, 'owner-e110@example.test');
  const api = page.locator('article').filter({ has: page.getByRole('heading', { name: 'API и вебхуки', exact: true }) });
  await api.getByRole('link', { name: 'Настроить' }).click();
  await expect(page).toHaveURL(/\/integrations\/api$/);
  await expect(page.getByRole('heading', { name: 'API-ключи и вебхуки' })).toBeVisible();
  await page.goto(`${origin}/integrations`);
  const notifications = page.locator('article').filter({ has: page.getByRole('heading', { name: 'Уведомления компании', exact: true }) });
  await notifications.getByRole('link', { name: 'Настроить' }).click();
  await expect(page).toHaveURL(/\/integrations\/notifications$/);
  await expect(page.getByText('Настройка и доставка уведомлений компании пока недоступны.')).toBeVisible();
});
