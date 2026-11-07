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

test.skip(process.env.RUN_ACCEPTANCE_E901E !== '1', 'Requires a dedicated acceptance database and built assets.');
test.use({ trace: 'off', screenshot: 'off', video: 'off', launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => {
  if (process.env.APP_ENV !== 'testing' || process.env.DB_DATABASE !== 'souzpir_test_e901e' || process.env.DB_TEST_DATABASE !== 'souzpir_test_e901e' || process.env.APP_PREVIEW_DATABASE === process.env.DB_DATABASE || process.env.DB_USERNAME !== 'souzpir_e901e' || !/^127\./.test(process.env.DB_HOST ?? '')) throw new Error('Dedicated SP-E9-01 testing database required.');
  await mkdir('.local/tmp', { recursive: true });
  const env = { ...process.env, AI_DRIVER: 'fake', DELIVERY_DRIVER: 'fake', MAIL_MAILER: 'log', AUTH_EMAIL_DELIVERY_ENABLED: 'false', QUEUE_CONNECTION: 'sync', CACHE_STORE: 'array', SESSION_DRIVER: 'file', CABINET_ORGANIZATION_ID: organization, E901_ORG: organization, AUTH_BOOTSTRAP_TOKEN_HASH: createHash('sha256').update(bootstrapCode).digest('hex'), INVITATION_ISSUANCE_HMAC_KEY: 'base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=' };
  execFileSync(php, ['tests/Browser/Acceptance/followups-e901e-fixture.php'], { env: { ...env, E901_ACTION: 'setup' }, stdio: 'pipe', timeout: 120000 });
  fixtureEnv = env;
  const probe = createServer();
  const port = await new Promise<number>((resolve, reject) => { probe.once('error', reject); probe.listen(0, '127.0.0.1', () => { const address = probe.address(); if (!address || typeof address === 'string') return reject(new Error('No local port')); probe.close(() => resolve(address.port)); }); });
  origin = `http://127.0.0.1:${port}`;
  server = spawn(php, ['-S', `127.0.0.1:${port}`, '-t', 'public', 'tests/Browser/Acceptance/followups-e901e-router.php'], { env: { ...env, APP_URL: origin }, stdio: 'ignore', windowsHide: true });
  for (let i = 0; i < 100; i++) { try { const response = await fetch(`${origin}/login`); if (response.ok) return; } catch { /* wait for local server */ } await new Promise(resolve => setTimeout(resolve, 200)); }
  throw new Error('Local acceptance server did not start.');
});
test.afterAll(() => { server?.kill(); });
async function auditPage(page: Page, name: string): Promise<void> {
  await expect.poll(() => page.title()).not.toBe('');
  await page.addScriptTag({ path: 'node_modules/axe-core/axe.min.js' });
  await mkdir('.local/screenshots/SP-E9-01E', { recursive: true });
  for (const width of [1440, 1024, 390]) {
    await page.setViewportSize({ width, height: 900 });
    const violations = await page.evaluate(async () => {
      const axe = (window as unknown as { axe: { run: (root: Document, options: object) => Promise<{ violations: { impact: string; id: string }[] }> } }).axe;
      return (await axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21aa'] } })).violations.filter(v => v.impact === 'critical' || v.impact === 'serious').map(v => v.id);
    });
    await page.screenshot({ path: `.local/screenshots/SP-E9-01E/${name}-${width}.png`, fullPage: true, animations: 'disabled' });
    expect(violations).toEqual([]);
  }
}
test('followup settings and new widget dialogue through the UI', async ({ page, browser }) => {
  test.setTimeout(180000);
  await page.route('**/*', route => new URL(route.request().url()).origin === origin ? route.continue() : route.abort('blockedbyclient'));
  page.setDefaultTimeout(12000);
  await page.goto(`${origin}/login`);
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(`${origin}/register`);
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
  const link = execFileSync(php, ['tests/Browser/Acceptance/followups-e901e-fixture.php'], { env: { ...fixtureEnv, E901_ACTION: 'verify-link', E901_EMAIL: ownerEmail, APP_URL: origin }, encoding: 'utf8' }).trim();
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
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.getByLabel('Название бренда').fill('Тестовый бренд');
  await page.getByLabel('Адрес сайта').fill('https://example.test');
  await page.getByRole('radio', { name: /Пиротехника/ }).check();
  await page.getByRole('button', { name: 'Сохранить и продолжить' }).click();
  await expect(page.getByRole('heading', { name: 'Выберите модули' })).toBeVisible();
  const seller = page.getByRole('switch', { name: /ИИ-продавец/ });
  if (await seller.getAttribute('aria-checked') !== 'true') await seller.locator('xpath=ancestor::label').click();
  await page.getByRole('button', { name: 'Сохранить и продолжить' }).click();
  await expect(page.getByRole('heading', { name: 'Первый шаг', exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Завершить настройку' }).click();
  await expect(page.getByRole('heading', { name: 'Настройка завершена' })).toBeVisible();
  await page.reload();
  await expect(page.getByText('Данные компании и бренда сохранены.')).toBeVisible();

  const brand = execFileSync(php, ['tests/Browser/Acceptance/followups-e901e-fixture.php'], { env: { ...fixtureEnv, E901_ACTION: 'brand-id' }, encoding: 'utf8' }).trim();
  await page.goto(`${origin}/brands/${brand}/modules`);
  await page.getByRole('button', { name: 'Подключить ИИ-продавец' }).click();
  await expect(page.getByRole('switch', { name: 'Отключить ИИ-продавец' })).toHaveAttribute('aria-checked', 'true');
  await page.goto(`${origin}/seller/settings/policy`);
  const reminders = page.locator('section').filter({ has: page.getByRole('heading', { name: 'Напоминания', exact: true }) });
  await reminders.getByLabel('Подготавливать напоминания').check();
  await reminders.getByLabel('Первый интервал, часов').fill('20');
  await reminders.getByLabel('Второй интервал, часов').fill('40');
  await reminders.getByLabel('Максимум напоминаний').fill('2');
  await reminders.getByRole('button', { name: 'Сохранить', exact: true }).click();
  await expect(page.getByRole('status')).toContainText('сохран');
  await page.reload();
  await expect(reminders.getByLabel('Подготавливать напоминания')).toBeChecked();
  await expect(reminders.getByLabel('Первый интервал, часов')).toHaveValue('20');
  await auditPage(page, 'followup-policy');
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(`${origin}/seller/channels/widget`);
  await page.getByLabel('Адреса сайтов').fill(origin);
  await page.getByLabel('Включить виджет на разрешённых сайтах').check();
  await page.getByLabel('Открыть через секунд').fill('1');
  await page.getByLabel('Запрашивать контакт с согласием').check();
  await page.getByLabel('HTTPS-ссылка на политику обработки данных').fill('https://example.test/privacy');
  const code = await page.locator('pre code').innerText();
  const key = new URL(code.match(/src="([^"]+)"/)![1]).searchParams.get('key');
  expect(key).toMatch(/^[A-Za-z0-9]{64}$/);
  await page.getByRole('button', { name: 'Сохранить настройки' }).click();
  await expect(page.getByRole('status')).toContainText('Настройки виджета сохранены');
  const visitorContext = await browser.newContext();
  try {
    const visitor = await visitorContext.newPage();
    await visitor.route('**/*', route => new URL(route.request().url()).origin === origin ? route.continue() : route.abort('blockedbyclient'));
    await visitor.goto(`${origin}/__acceptance/widget?key=${key}`);
    const widget = visitor.locator('#souzpir-widget');
    await expect(widget.getByRole('checkbox')).toBeVisible({ timeout: 10000 });
    await widget.getByRole('checkbox').check();
    await widget.getByLabel('Имя', { exact: true }).fill('Посетитель виджета');
    await widget.getByLabel('Электронная почта').fill('widget@example.test');
    await widget.getByRole('button', { name: 'Оставить контакт' }).click();
    await expect(widget.getByText('Спасибо! Контакт сохранён.')).toBeVisible();
  } finally { await visitorContext.close(); }
  await page.goto(`${origin}/seller/dialogs`);
  await expect(page.getByText('Посетитель виджета')).toBeVisible();
  await auditPage(page, 'new-dialogue');
});
