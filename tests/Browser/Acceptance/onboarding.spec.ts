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

test.skip(process.env.RUN_ACCEPTANCE_E901 !== '1', 'Requires a dedicated acceptance database and built assets.');
test.use({ trace: 'off', screenshot: 'off', video: 'off', launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => {
  if (process.env.APP_ENV !== 'testing' || process.env.DB_DATABASE !== 'souzpir_test_e901' || process.env.DB_TEST_DATABASE !== 'souzpir_test_e901' || process.env.APP_PREVIEW_DATABASE === process.env.DB_DATABASE || process.env.DB_USERNAME !== 'souzpir_e901' || !/^127\./.test(process.env.DB_HOST ?? '')) throw new Error('Dedicated SP-E9-01 testing database required.');
  await mkdir('.local/tmp', { recursive: true });
  const env = { ...process.env, AI_DRIVER: 'fake', DELIVERY_DRIVER: 'fake', MAIL_MAILER: 'log', AUTH_EMAIL_DELIVERY_ENABLED: 'false', QUEUE_CONNECTION: 'sync', CACHE_STORE: 'array', SESSION_DRIVER: 'file', CABINET_ORGANIZATION_ID: organization, E901_ORG: organization, AUTH_BOOTSTRAP_TOKEN_HASH: createHash('sha256').update(bootstrapCode).digest('hex'), INVITATION_ISSUANCE_HMAC_KEY: 'base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=' };
  execFileSync(php, ['tests/Browser/Acceptance/fixture.php'], { env: { ...env, E901_ACTION: 'setup' }, stdio: 'pipe', timeout: 120000 });
  fixtureEnv = env;
  const probe = createServer();
  const port = await new Promise<number>((resolve, reject) => { probe.once('error', reject); probe.listen(0, '127.0.0.1', () => { const address = probe.address(); if (!address || typeof address === 'string') return reject(new Error('No local port')); probe.close(() => resolve(address.port)); }); });
  origin = `http://127.0.0.1:${port}`;
  server = spawn(php, ['-S', `127.0.0.1:${port}`, '-t', 'public', 'tests/Browser/Acceptance/router.php'], { env: { ...env, APP_URL: origin }, stdio: 'ignore', windowsHide: true });
  for (let i = 0; i < 100; i++) { try { const response = await fetch(`${origin}/login`); if (response.ok) return; } catch { /* wait for local server */ } await new Promise(resolve => setTimeout(resolve, 200)); }
  throw new Error('Local acceptance server did not start.');
});
test.afterAll(() => { server?.kill(); });
async function auditPage(page: Page, name: string): Promise<void> {
  await expect.poll(() => page.title()).not.toBe('');
  await page.addScriptTag({ path: 'node_modules/axe-core/axe.min.js' });
  await mkdir('.local/screenshots/SP-E9-01', { recursive: true });
  for (const width of [1440, 1024, 390]) {
    await page.setViewportSize({ width, height: 900 });
    const violations = await page.evaluate(async () => {
      const axe = (window as unknown as { axe: { run: (root: Document, options: object) => Promise<{ violations: { impact: string; id: string }[] }> } }).axe;
      return (await axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21aa'] } })).violations.filter(v => v.impact === 'critical' || v.impact === 'serious').map(v => v.id);
    });
    await page.screenshot({ path: `.local/screenshots/SP-E9-01/${name}-${width}.png`, fullPage: true, animations: 'disabled' });
    expect(violations).toEqual([]);
  }
}
test('registration, invitation, widget and questionnaire work across brands', async ({ page, browser }) => {
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
  const link = execFileSync(php, ['tests/Browser/Acceptance/fixture.php'], { env: { ...fixtureEnv, E901_ACTION: 'verify-link', E901_EMAIL: ownerEmail, APP_URL: origin }, encoding: 'utf8' }).trim();
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
  await page.goto(`${origin}/team`);
  await page.getByRole('button', { name: 'Пригласить участника' }).click();
  const dialog = page.getByRole('dialog', { name: 'Пригласить участника' });
  const guestEmail = `guest-${randomBytes(10).toString('hex')}@example.test`;
  await dialog.getByLabel('Электронная почта').fill(guestEmail);
  await dialog.getByRole('button', { name: 'Продолжить' }).click();
  await dialog.getByRole('button', { name: 'Подтвердить выпуск' }).click();
  const field = dialog.getByLabel('Ссылка приглашения');
  await expect(field).toBeVisible();
  const invitation = await field.inputValue();
  expect(new URL(invitation).origin).toBe(origin);
  await dialog.getByRole('button', { name: 'Закрыть' }).click();
  const context = await browser.newContext();
  try {
    const guest = await context.newPage();
    await guest.route('**/*', route => new URL(route.request().url()).origin === origin ? route.continue() : route.abort('blockedbyclient'));
    await guest.goto(invitation);
    await expect(guest).toHaveURL(/\/invitations\/show$/);
    await guest.getByLabel('Имя', { exact: true }).fill('Тестовый участник');
    await guest.getByLabel('Пароль', { exact: true }).fill('test-only-password-123');
    await guest.getByLabel('Повторите пароль').fill('test-only-password-123');
    await guest.getByLabel('Я принимаю условия использования и обработки данных').check();
    await guest.getByRole('button', { name: 'Создать аккаунт' }).click();
    await expect(guest).toHaveURL(/\/email\/verify$/);
    const guestLink = execFileSync(php, ['tests/Browser/Acceptance/fixture.php'], { env: { ...fixtureEnv, E901_ACTION: 'verify-link', E901_EMAIL: guestEmail, APP_URL: origin }, encoding: 'utf8' }).trim();
    await guest.goto(guestLink);
    await guest.getByRole('button', { name: 'Подтвердить адрес' }).click();
    await expect(guest).toHaveURL(/\/invitations\/show$/);
    await guest.getByRole('button', { name: 'Принять приглашение' }).click();
    await expect(guest).toHaveURL(/\/auth\/ready$/);
  } finally { await context.close(); }
  await page.reload();
  await expect(page.getByRole('region', { name: 'Таблица участников' }).getByRole('row').filter({ hasText: guestEmail })).toBeVisible();
  const brands = JSON.parse(execFileSync(php, ['tests/Browser/Acceptance/fixture.php'], { env: { ...fixtureEnv, E901_ACTION: 'seed-dialogs' }, encoding: 'utf8' })) as { first: string; second: string };
  await page.goto(`${origin}/seller/dialogs`);
  await expect(page.getByText('Клиент первого бренда')).toBeVisible();
  await expect(page.getByText('Клиент второго бренда')).toHaveCount(0);
  await page.goto(`${origin}/brands/${brands.first}/settings`);
  await page.getByRole('combobox', { name: 'Выбрать бренд' }).click();
  const switchForward = page.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname === '/brand/switch');
  await page.getByRole('option', { name: 'Второй тестовый бренд' }).click();
  expect((await switchForward).status()).toBe(302);
  await expect.poll(() => page.evaluate(() => localStorage.getItem('souzpir.profile-brand-switch.retry'))).toBeNull();
  await page.goto(`${origin}/seller/dialogs`);
  await expect(page.getByText('Клиент второго бренда')).toBeVisible();
  await expect(page.getByText('Клиент первого бренда')).toHaveCount(0);
  await page.reload();
  await expect(page.getByText('Клиент второго бренда')).toBeVisible();
  await expect(page.getByText('Клиент первого бренда')).toHaveCount(0);

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
  await page.goto(`${origin}/seller/customers`);
  const customer = page.getByRole('row').filter({ hasText: 'Посетитель виджета' });
  await expect(customer).toBeVisible();
  await customer.getByText('Посетитель виджета').click();
  await expect(page.getByText('widget@example.test')).toBeVisible();
  await page.goto(`${origin}/brands/${brands.second}/settings`);
  await page.getByRole('combobox', { name: 'Выбрать бренд' }).click();
  const switchBack = page.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname === '/brand/switch');
  await page.getByRole('option', { name: 'Тестовый бренд', exact: true }).click();
  expect((await switchBack).status()).toBe(302);
  await expect.poll(() => page.evaluate(() => localStorage.getItem('souzpir.profile-brand-switch.retry'))).toBeNull();
  await page.goto(`${origin}/seller/customers`);
  await expect(page.getByText('Посетитель виджета')).toHaveCount(0);

  await page.goto(`${origin}/brands/${brands.first}/modules`);
  await page.getByRole('button', { name: 'Подключить Видение' }).click();
  await expect(page.getByRole('switch', { name: 'Отключить Видение' })).toHaveAttribute('aria-checked', 'true');
  await page.goto(`${origin}/vision`);
  await page.getByRole('button', { name: 'Начать анкету' }).click();
  await expect(page).toHaveURL(/\/vision\/quiz\/1$/);
  await page.getByLabel('Сайт', { exact: true }).fill('https://example.com');
  await page.getByLabel('Название бренда', { exact: true }).fill('Тестовый бренд');
  await page.getByRole('button', { name: 'Сохранить и продолжить' }).click();
  await expect(page).toHaveURL(/\/vision\/quiz\/2$/);
  for (const alias of ['example.com', 'Тестовый бренд']) {
    await page.getByLabel('Добавить: Официальные названия').fill(alias);
    await page.getByRole('button', { name: 'Добавить в Официальные названия' }).click();
  }
  await page.getByRole('button', { name: 'Сохранить и продолжить' }).click();
  await expect(page).toHaveURL(/\/vision\/quiz\/3$/);
  await page.getByLabel('Добавить: Категории товаров и услуг').fill('Пиротехника');
  await page.getByRole('button', { name: 'Добавить в Категории товаров и услуг' }).click();
  await page.getByLabel('Товары', { exact: true }).check();
  await page.getByLabel('Оба', { exact: true }).check();
  await page.getByLabel('Как покупатель описывает потребность').fill('купить салют для праздника');
  await page.getByLabel('Категория: именительный падеж').fill('салюты');
  await page.getByLabel('Категория: родительный падеж, множественное число').fill('салютов');
  for (let step = 3; step <= 7; step++) {
    await page.getByRole('button', { name: 'Сохранить и продолжить' }).click();
    await expect(page).toHaveURL(new RegExp(`/vision/quiz/${step + 1}$`));
  }
  await page.getByRole('button', { name: 'Отправить анкету' }).click();
  await expect(page).toHaveURL(/\/vision\/profile$/);
  await expect(page.getByRole('heading', { name: 'Профиль бренда' })).toBeVisible();
  await expect(page.getByText('Тестовый бренд').first()).toBeVisible();

});
