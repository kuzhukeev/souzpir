import { expect, test, type Page } from '@playwright/test';
import { spawn, execFileSync, type ChildProcess } from 'node:child_process';
import { createServer } from 'node:net';
import { createHash, randomBytes } from 'node:crypto';
import { mkdir } from 'node:fs/promises';

let server: ChildProcess; let origin: string;
const php = process.env.ACCEPTANCE_PHP_PATH || 'php';
const alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
const organization = (() => { let value = BigInt(`0x${randomBytes(16).toString('hex')}`); let id = ''; for (let i = 0; i < 26; i++) { id = alphabet[Number(value & 31n)] + id; value >>= 5n; } return id; })();
const ownerEmail = `owner-${randomBytes(10).toString('hex')}@example.test`;
const bootstrapCode = 'acceptance-synthetic-bootstrap-code-2026';
let fixtureEnv: NodeJS.ProcessEnv;

test.skip(process.env.RUN_ACCEPTANCE_E901C !== '1', 'Requires a dedicated acceptance database and built assets.');
test.use({ trace: 'off', screenshot: 'off', video: 'off', launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => {
  if (process.env.APP_ENV !== 'testing' || process.env.DB_DATABASE !== 'souzpir_test_e901c' || process.env.DB_TEST_DATABASE !== 'souzpir_test_e901c' || process.env.APP_PREVIEW_DATABASE === process.env.DB_DATABASE || process.env.DB_USERNAME !== 'souzpir_e901c' || !/^127\./.test(process.env.DB_HOST ?? '')) throw new Error('Dedicated SP-E9-01 testing database required.');
  await mkdir('.local/tmp', { recursive: true });
  const env = { ...process.env, AI_DRIVER: 'fake', DELIVERY_DRIVER: 'fake', MAIL_MAILER: 'log', AUTH_EMAIL_DELIVERY_ENABLED: 'false', QUEUE_CONNECTION: 'sync', CACHE_STORE: 'array', SESSION_DRIVER: 'file', CABINET_ORGANIZATION_ID: organization, E901_ORG: organization, AUTH_BOOTSTRAP_TOKEN_HASH: createHash('sha256').update(bootstrapCode).digest('hex'), INVITATION_ISSUANCE_HMAC_KEY: 'base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=' };
  execFileSync(php, ['tests/Browser/Acceptance/context-fixture.php'], { env: { ...env, E901_ACTION: 'setup' }, stdio: 'pipe', timeout: 120000 });
  fixtureEnv = env;
  const probe = createServer();
  const port = await new Promise<number>((resolve, reject) => { probe.once('error', reject); probe.listen(0, '127.0.0.1', () => { const address = probe.address(); if (!address || typeof address === 'string') return reject(new Error('No local port')); probe.close(() => resolve(address.port)); }); });
  origin = `http://127.0.0.1:${port}`;
  server = spawn(php, ['-S', `127.0.0.1:${port}`, '-t', 'public', 'tests/Browser/Acceptance/context-router.php'], { env: { ...env, APP_URL: origin }, stdio: 'ignore', windowsHide: true });
  for (let i = 0; i < 100; i++) { try { const response = await fetch(`${origin}/login`); if (response.ok) return; } catch { /* wait for local server */ } await new Promise(resolve => setTimeout(resolve, 200)); }
  throw new Error('Local acceptance server did not start.');
});
test.afterAll(() => { server?.kill(); });
async function auditPage(page: Page, name: string): Promise<void> {
  await expect.poll(() => page.title()).not.toBe('');
  await page.addScriptTag({ path: 'node_modules/axe-core/axe.min.js' });
  await mkdir('.local/screenshots/SP-E9-01C', { recursive: true });
  for (const width of [1440, 1024, 390]) {
    await page.setViewportSize({ width, height: 900 });
    const violations = await page.evaluate(async () => {
      const axe = (window as unknown as { axe: { run: (root: Document, options: object) => Promise<{ violations: { impact: string; id: string }[] }> } }).axe;
      return (await axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21aa'] } })).violations.filter(v => v.impact === 'critical' || v.impact === 'serious').map(v => v.id);
    });
    await page.screenshot({ path: `.local/screenshots/SP-E9-01C/${name}-${width}.png`, fullPage: true, animations: 'disabled' });
    expect(violations).toEqual([]);
  }
}
test('dialog context follows the selected brand', async ({ page }) => {
  test.setTimeout(180000);
  await page.route('**/*', route => new URL(route.request().url()).origin === origin ? route.continue() : route.abort('blockedbyclient'));
  page.setDefaultTimeout(12000);
  await page.goto(`${origin}/login`);
  await auditPage(page, 'login');
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(`${origin}/register`);
  await auditPage(page, 'register');
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.getByLabel('Имя', { exact: true }).fill('Тестовый владелец');
  await page.getByLabel('Рабочая почта').fill(ownerEmail);
  await page.getByLabel('Пароль', { exact: true }).fill('test-only-password-123');
  await page.getByLabel('Повторите пароль').fill('test-only-password-123');
  await page.getByLabel('Код создания кабинета').fill(bootstrapCode);
  await page.getByLabel('Я принимаю условия использования и обработки данных').check();
  await page.getByLabel('Название компании и тексты диалогов обрабатываются внешними ИИ-провайдерами через OpenRouter').check();
  await page.getByRole('button', { name: 'Зарегистрироваться' }).click();
  await expect(page).toHaveURL(/\/email\/verify$/);
  const link = execFileSync(php, ['tests/Browser/Acceptance/context-fixture.php'], { env: { ...fixtureEnv, E901_ACTION: 'verify-link', E901_EMAIL: ownerEmail, APP_URL: origin }, encoding: 'utf8' }).trim();
  await page.goto(link);
  await page.getByRole('button', { name: 'Подтвердить адрес' }).click();
  await expect(page.getByRole('heading', { name: 'Почта подтверждена' })).toBeVisible();
  await page.getByRole('link', { name: 'Продолжить' }).click();
  await page.goto(`${origin}/start`);
  await page.getByLabel('Название компании').fill('Тестовая компания');
  await page.getByLabel('Регион').fill('Москва');
  await page.getByRole('button', { name: 'Сохранить и продолжить' }).click();
  await expect(page.getByRole('heading', { name: 'Сайт и бренд' })).toBeVisible();
  await page.reload();
  await expect(page.getByRole('heading', { name: 'Сайт и бренд' })).toBeVisible();
  await auditPage(page, 'onboarding');
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.getByLabel('Название бренда').fill('Тестовый бренд');
  await page.getByLabel('Адрес сайта').fill('https://example.test');
  await page.getByRole('radio', { name: /Пиротехника/ }).check();
  await page.getByRole('button', { name: 'Сохранить и продолжить' }).click();
  await expect(page.getByRole('heading', { name: 'Выберите модули' })).toBeVisible();
  await page.getByRole('switch', { name: /ИИ-продавец/ }).locator('xpath=ancestor::label').click();
  const vision = page.getByRole('switch', { name: /Видение/ });
  if (await vision.getAttribute('aria-checked') !== 'true') await vision.locator('xpath=ancestor::label').click();
  await page.getByRole('button', { name: 'Сохранить и продолжить' }).click();
  await expect(page.getByRole('heading', { name: 'Первый шаг', exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Завершить настройку' }).click();
  await expect(page.getByRole('heading', { name: 'Настройка завершена' })).toBeVisible();
  await page.reload();
  await expect(page.getByText('Данные компании и бренда сохранены.')).toBeVisible();
  const brands = JSON.parse(execFileSync(php, ['tests/Browser/Acceptance/context-fixture.php'], { env: { ...fixtureEnv, E901_ACTION: 'seed-dialogs' }, encoding: 'utf8' })) as { first: string; second: string; firstDialog: string; secondDialog: string };
  await page.goto(`${origin}/seller/dialogs/${brands.firstDialog}`);
  await expect(page.getByText('Клиент первого бренда').first()).toBeVisible();
  await expect(page.getByText('Клиент второго бренда')).toHaveCount(0);
  // The list currently renders an intentionally disabled brand control.
  await expect(page.getByText('Тестовый бренд · Скоро')).toBeVisible();
  await auditPage(page, 'dialog-first');
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(`${origin}/brands/${brands.first}/settings`);
  await page.getByRole('combobox', { name: 'Выбрать бренд' }).click();
  const switching = page.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname === '/brand/switch');
  await page.getByRole('option', { name: 'Второй тестовый бренд' }).click();
  expect((await switching).status()).toBe(302);
  await expect.poll(() => page.evaluate(() => Object.keys(localStorage).filter(key => key.startsWith('souzpir.profile-context.pending.') || key === 'souzpir.profile-brand-switch.retry'))).toEqual([]);
  const old = await page.request.get(`${origin}/seller/dialogs/${brands.firstDialog}`, { maxRedirects: 0 });
  expect(old.status()).toBe(404);
  await page.goto(`${origin}/seller/dialogs/${brands.secondDialog}`);
  await expect(page.getByText('Клиент второго бренда').first()).toBeVisible();
  await expect(page.getByText('Клиент первого бренда')).toHaveCount(0);
  await page.reload();
  await expect(page.getByText('Клиент второго бренда').first()).toBeVisible();
  await expect(page.getByText('Клиент первого бренда')).toHaveCount(0);
  await auditPage(page, 'dialog-second');
});
