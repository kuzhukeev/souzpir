import { expect, test, type Page } from '@playwright/test';

// Never attach a browser snapshot, trace, video, or failure prompt containing a live test key.
process.env.PLAYWRIGHT_NO_COPY_PROMPT = '1';
test.skip(process.env.RUN_E110_REAL_BROWSER !== '1', 'Requires the isolated E1-10 browser fixture.');
test.use({
  trace: 'off', screenshot: 'off', video: 'off',
  launchOptions: { executablePath: process.env.E110_CHROMIUM_PATH },
});
const origin = 'http://127.0.0.1:18110';
const assets = 'http://127.0.0.1:5237';

async function readBrand(page: Page, key: string, brand?: string) {
  return page.evaluate(async ({ key, brand }) => {
    try {
      const headers: Record<string, string> = { Authorization: `Bearer ${key}` };
      if (brand) headers['X-Brand-Id'] = brand;
      const response = await fetch('/api/v1/brand', { credentials: 'omit', headers });
      const data = response.ok ? await response.json() as { id?: string } : null;
      return { status: response.status, id: data?.id ?? null };
    } catch {
      return { status: 0, id: null };
    }
  }, { key, brand });
}

async function createKey(page: Page, name: string, ability: string): Promise<string> {
  await page.getByRole('button', { name: 'Создать ключ' }).click();
  const dialog = page.getByRole('dialog', { name: 'Создать API-ключ' });
  await dialog.getByLabel('Название ключа').fill(name);
  await dialog.getByLabel(ability).check();
  await dialog.getByRole('button', { name: 'Создать', exact: true }).click();
  await expect(page.getByRole('row').filter({ hasText: name })).toBeVisible();
  await page.getByRole('button', { name: 'Показать ключ один раз' }).click();
  const key = await page.getByLabel('Созданный API-ключ').innerText();
  expect(/^[1-9][0-9]*\|[A-Za-z0-9]{64}$/.test(key)).toBe(true);
  return key;
}

test('реальный UI создаёт, раскрывает и отзывает ключи через действующий API', async ({ page }) => {
  await page.route('**/*', (route) => [origin, assets].includes(new URL(route.request().url()).origin) ? route.continue() : route.abort('blockedbyclient'));
  await page.goto(`${origin}/login`);
  await page.getByLabel('Рабочая почта').fill('owner-e110@example.test');
  await page.getByLabel('Пароль', { exact: true }).fill('test-only-password-123');
  await page.getByRole('button', { name: 'Войти', exact: true }).click();
  await expect(page).toHaveURL(/auth\/ready/);
  await page.goto(`${origin}/integrations/api`);
  const context = await page.evaluate(() => {
    const item = document.querySelector('script[data-page="app"]');
    if (!item) throw new Error('Missing Inertia page');
    return (JSON.parse(item.textContent || '{}') as { props: { brand: { id: string }; brands: { id: string }[] } }).props;
  });
  const otherBrand = context.brands.find((brand) => brand.id !== context.brand.id)?.id;
  expect(Boolean(otherBrand)).toBe(true);
  const name = `Read ${Date.now()}`;
  const key = await createKey(page, name, 'Чтение');
  expect(await page.evaluate((value) => !JSON.stringify(history.state).includes(value) && !JSON.stringify(localStorage).includes(value) && !JSON.stringify(sessionStorage).includes(value), key)).toBe(true);
  const allowed = await readBrand(page, key);
  expect(allowed.status).toBe(200);
  expect(allowed.id).toBe(context.brand.id);
  expect((await readBrand(page, key, otherBrand)).status).toBe(404);
  await page.getByRole('dialog', { name: 'Сохраните ключ' }).getByRole('button', { name: 'Готово' }).click();
  expect(await page.getByLabel('Созданный API-ключ').count()).toBe(0);
  const repeat = await page.evaluate(async () => {
    const csrf = document.cookie.split('; ').find((cookie) => cookie.startsWith('XSRF-TOKEN='));
    if (!csrf) return 0;
    try {
      return (await fetch('/integrations/api/secrets', { method: 'POST', credentials: 'same-origin', headers: { 'X-XSRF-TOKEN': decodeURIComponent(csrf.slice('XSRF-TOKEN='.length)), Accept: 'application/json', 'Content-Type': 'application/json' }, body: JSON.stringify({ type: 'api_key' }) })).status;
    } catch { return 0; }
  });
  expect(repeat).toBe(404);
  await page.getByRole('row').filter({ hasText: name }).getByRole('button', { name: 'Отозвать' }).click();
  await expect(page.getByRole('row').filter({ hasText: name })).toHaveCount(0);
  expect((await readBrand(page, key)).status).toBe(401);
  const leadsName = `Leads ${Date.now()}`;
  const leads = await createKey(page, leadsName, 'Лиды');
  expect((await readBrand(page, leads)).status).toBe(403);
  await page.getByRole('dialog', { name: 'Сохраните ключ' }).getByRole('button', { name: 'Готово' }).click();
  await page.getByRole('row').filter({ hasText: leadsName }).getByRole('button', { name: 'Отозвать' }).click();
  await expect(page.getByRole('row').filter({ hasText: leadsName })).toHaveCount(0);
});
