import { expect, test } from '@playwright/test';

const origin = process.env.E110_DATE_ORIGIN ?? 'http://127.0.0.1:18110';
const assets = process.env.E110_DATE_ASSET_ORIGIN ?? 'http://127.0.0.1:5237';
test.skip(process.env.RUN_E110_DATE_BROWSER !== '1', 'Requires the isolated E1-10 browser fixture.');
test.use({
  trace: 'off',
  screenshot: 'off',
  video: 'off',
  launchOptions: { executablePath: process.env.E110_CHROMIUM_PATH },
});

test('активный канал показывает статус без неподтверждённой даты подключения', async ({ page }) => {
  for (const address of [origin, assets]) {
    const parsed = new URL(address);
    expect(parsed.protocol).toBe('http:');
    expect(parsed.hostname).toBe('127.0.0.1');
  }
  await page.route('**/*', (route) =>
    [origin, assets].includes(new URL(route.request().url()).origin)
      ? route.continue()
      : route.abort('blockedbyclient'),
  );
  await page.goto(`${origin}/login`);
  await page.getByLabel('Рабочая почта').fill('owner-e110@example.test');
  await page.getByLabel('Пароль', { exact: true }).fill('test-only-password-123');
  await page.getByRole('button', { name: 'Войти', exact: true }).click();
  await expect(page).toHaveURL(/auth\/ready/);
  await page.goto(`${origin}/integrations`);
  const initial = await page.evaluate(() => {
    const script = document.querySelector('script[data-page="app"]');
    if (!script) throw new Error('Missing Inertia page');
    return (JSON.parse(script.textContent || '{}') as {
      props: { connections: { id: string; status: string; connected_at: string | null }[] };
    }).props.connections.find((item) => item.id === 'telegram');
  });
  expect(initial?.status).toBe('active');
  expect(initial?.connected_at).toBeNull();
  const card = page.locator('article').filter({
    has: page.getByRole('heading', { name: 'Telegram', exact: true }),
  });
  for (const width of [390, 1440]) {
    await page.setViewportSize({ width, height: 900 });
    await expect(card.getByText('Подключён', { exact: true })).toBeVisible();
    await expect(card).not.toContainText('02.01.2020');
    await expect(card).not.toContainText(/Подключён\s+\d/);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  }
  await page.setViewportSize({ width: 390, height: 900 });
  await page.screenshot({ path: '.local/screenshots/e1-10/connection-status-without-date-390.png', fullPage: true });
});
