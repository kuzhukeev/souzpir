import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';

test.use({ launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
let server: ViteDevServer;
let origin: string;
test.beforeAll(async () => {
  server = await createServer({ configFile: 'tests/Browser/E4-03F/vite.config.ts', cacheDir: '.local/runtime/vite-e403f', server: { host: '127.0.0.1', port: 0, watch: null } });
  await server.listen();
  origin = server.resolvedUrls!.local[0];
});
test.afterAll(async () => { await server?.close(); });

test('четыре условия выбираются и передаются при сохранении факта', async ({ page }) => {
  await page.goto(new URL('/tests/Browser/E4-03F/fact.html?card', origin).href);
  for (const value of ['min_order_quantity', 'prepayment_pct', 'availability', 'other_conditions']) {
    await page.getByLabel('Добавить условие').selectOption(value);
    await expect(page.getByLabel('Факт', { exact: true })).toHaveValue(new RegExp(`\\{cond:${value}\\}`));
  }
  await page.route('**/seller/knowledge/facts/**', route => route.abort('failed'));
  const sent = page.waitForRequest(request => request.method() === 'POST' && request.url().includes('/seller/knowledge/facts/'));
  await page.getByRole('button', { name: 'Сохранить факт' }).click();
  const text = (await sent).postDataJSON().text as string;
  for (const value of ['min_order_quantity', 'prepayment_pct', 'availability', 'other_conditions']) expect(text).toContain(`{cond:${value}}`);
  await expect(page.getByRole('alert')).toBeVisible();
});

test('меню карточек работает с клавиатуры и показывает реальные ссылки', async ({ page }) => {
  for (const [width, theme] of [[1440, 'neutral'], [1024, 'pyro'], [390, 'confectionery']] as const) {
    await page.setViewportSize({ width, height: 900 });
    await page.goto(new URL(`/tests/Browser/E4-03F/conditions.html?theme=${theme}`, origin).href);
    const trigger = page.getByLabel('Действия: Минимальный заказ');
    await trigger.focus();
    await page.keyboard.press('Enter');
    await expect(page.getByRole('link', { name: 'К связанным фактам' })).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(trigger).toBeFocused();
    await expect(page.getByRole('link', { name: 'К связанным фактам' })).toHaveCount(0);
    await trigger.press('Enter');
    await page.getByRole('link', { name: 'К связанным фактам' }).click();
    await expect(page.getByRole('link', { name: 'Минимальная сумма {cond:min_order_rub}' })).toBeVisible();
    await expect(page.getByLabel('Действия: Сетка скидок')).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
    if (width === 1440) { await mkdir('.local/screenshots/SP-E4-03F', { recursive: true }); await page.screenshot({ path: '.local/screenshots/SP-E4-03F/conditions.png', fullPage: true }); }
  }
});

test('статусы карточек, «Утвердить все» и недостающие поля сохраняются', async ({ page }) => {
  await page.goto(new URL('/tests/Browser/E4-03F/conditions.html', origin).href);
  await expect(page.getByText('Не утверждено').first()).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Что бот скажет, если спросят про скидку' })).toBeVisible();
  await page.getByLabel('Кому доступна').fill('Постоянным клиентам');
  await page.getByLabel('По городу').fill('от 300 ₽');
  await page.getByLabel('По стране').fill('от 500 ₽');
  await page.getByLabel('Бесплатно от').fill('50 000 ₽');
  await page.getByRole('button', { name: 'Утвердить все' }).click();
  await expect(page.getByText('До публикации бот использует текущую версию условий.')).toBeVisible();
  await page.route('**/seller/knowledge/conditions', route => route.abort('failed'));
  const sent = page.waitForRequest(request => request.method() === 'POST' && request.url().includes('/seller/knowledge/conditions'));
  await page.getByRole('button', { name: 'Сохранить в черновик' }).click();
  const body = (await sent).postDataJSON();
  expect(body.deferred_payment.available_to).toBe('Постоянным клиентам');
  expect(body.delivery_by_city).toBe('от 300 ₽');
  expect(body.delivery_by_country).toBe('от 500 ₽');
  expect(body.delivery_free_from).toBe('50 000 ₽');
  expect(body.approved.min_order).toBe(true);
  expect(body.approved.delivery).toBe(true);
});
