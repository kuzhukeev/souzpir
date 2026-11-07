import { expect, test } from '@playwright/test';
import { spawn, execFileSync, type ChildProcess } from 'node:child_process';
import { createServer } from 'node:net';
import { createHash, randomBytes } from 'node:crypto';
import { mkdir, writeFile } from 'node:fs/promises';

let server: ChildProcess; let origin: string;
const php = process.env.ACCEPTANCE_PHP_PATH || 'php';
const alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
const organization = (() => { let value = BigInt(`0x${randomBytes(16).toString('hex')}`); let id = ''; for (let i = 0; i < 26; i++) { id = alphabet[Number(value & 31n)] + id; value >>= 5n; } return id; })();
const ownerEmail = `owner-${randomBytes(10).toString('hex')}@example.test`;
const bootstrapCode = 'acceptance-synthetic-bootstrap-code-2026';
let fixtureEnv: NodeJS.ProcessEnv;

test.skip(process.env.RUN_ACCEPTANCE_E901G !== '1', 'Requires a dedicated acceptance database and built assets.');
test.use({ trace: 'off', screenshot: 'off', video: 'off', launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => {
  if (process.env.APP_ENV !== 'testing' || process.env.DB_DATABASE !== 'souzpir_test_e901g' || process.env.DB_TEST_DATABASE !== 'souzpir_test_e901g' || process.env.APP_PREVIEW_DATABASE === process.env.DB_DATABASE || process.env.DB_USERNAME !== 'e901g_app' || !/^127\./.test(process.env.DB_HOST ?? '')) throw new Error('Dedicated SP-E9-01 testing database required.');
  await mkdir('.local/tmp', { recursive: true });
  const env = { ...process.env, AI_DRIVER: 'fake', DELIVERY_DRIVER: 'fake', MAIL_MAILER: 'log', AUTH_EMAIL_DELIVERY_ENABLED: 'false', QUEUE_CONNECTION: 'sync', CACHE_STORE: 'array', SESSION_DRIVER: 'file', CABINET_ORGANIZATION_ID: organization, E901_ORG: organization, AUTH_BOOTSTRAP_TOKEN_HASH: createHash('sha256').update(bootstrapCode).digest('hex'), INVITATION_ISSUANCE_HMAC_KEY: 'base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=' };
  execFileSync(php, ['tests/Browser/Acceptance/screens-e901g-fixture.php'], { env: { ...env, E901_ACTION: 'setup' }, stdio: 'pipe', timeout: 120000 });
  fixtureEnv = env;
  const probe = createServer();
  const port = await new Promise<number>((resolve, reject) => { probe.once('error', reject); probe.listen(0, '127.0.0.1', () => { const address = probe.address(); if (!address || typeof address === 'string') return reject(new Error('No local port')); probe.close(() => resolve(address.port)); }); });
  origin = `http://127.0.0.1:${port}`;
  server = spawn(php, ['-S', `127.0.0.1:${port}`, '-t', 'public', 'tests/Browser/Acceptance/screens-e901g-router.php'], { env: { ...env, APP_URL: origin }, stdio: 'ignore', windowsHide: true });
  for (let i = 0; i < 100; i++) { try { const response = await fetch(`${origin}/login`); if (response.ok) return; } catch { /* wait for local server */ } await new Promise(resolve => setTimeout(resolve, 200)); }
  throw new Error('Local acceptance server did not start.');
});
test.afterAll(() => { server?.kill(); });
test('real pages after creating knowledge through the UI', async ({ page }) => {
  test.setTimeout(600000);
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
  const link = execFileSync(php, ['tests/Browser/Acceptance/screens-e901g-fixture.php'], { env: { ...fixtureEnv, E901_ACTION: 'verify-link', E901_EMAIL: ownerEmail, APP_URL: origin }, encoding: 'utf8' }).trim();
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

  const brand = execFileSync(php, ['tests/Browser/Acceptance/screens-e901g-fixture.php'], { env: { ...fixtureEnv, E901_ACTION: 'brand-id' }, encoding: 'utf8' }).trim();
  await page.goto(`${origin}/brands/${brand}/modules`);
  await page.getByRole('button', { name: 'Подключить ИИ-продавец' }).click();
  await expect(page.getByRole('switch', { name: 'Отключить ИИ-продавец' })).toHaveAttribute('aria-checked', 'true');
  await page.goto(`${origin}/seller/knowledge`);
  await page.getByRole('button', { name: 'Создать черновик', exact: true }).click();
  await expect(page.getByRole('status').filter({ hasText: 'Черновик создан.' })).toBeVisible();
  await page.goto(`${origin}/seller/knowledge/facts`);
  await page.getByLabel('Ключ группы (латиница, цифры, дефис или подчёркивание)').fill('delivery');
  await page.getByLabel('Тема', { exact: true }).last().fill('Доставка');
  await page.getByRole('button', { name: 'Добавить группу' }).click();
  await expect(page.getByRole('status').filter({ hasText: 'Группа сохранена.' })).toBeVisible();
  await expect(page.getByRole('button', { name: /Доставка.*delivery/ })).toBeVisible();
  await page.locator('summary').filter({ hasText: 'Добавить факт' }).click();
  await page.locator('details').filter({ has: page.locator('summary').filter({ hasText: 'Добавить факт' }) }).locator('select').selectOption('delivery');
  await page.getByLabel('Текст факта').fill('Доставляем по России.');
  await page.getByRole('button', { name: 'Сохранить новый факт' }).click();
  await expect(page.getByText('Доставляем по России.').first()).toBeVisible();
  const factLink = await page.getByRole('link', { name: 'Карточка', exact: true }).first().getAttribute('href');
  if (!factLink) throw new Error('Fact link is missing');
  const paths = [
    ['d44', factLink], ['d41', '/seller/knowledge'], ['d43', '/seller/knowledge/facts'],
    ['d45', '/seller/knowledge/conditions'], ['d46', '/seller/knowledge/topics'],
    ['d48', '/seller/knowledge/fewshot'], ['d49', '/seller/knowledge/prohibitions'],
    ['d410', '/seller/knowledge/imports'], ['d411', '/seller/knowledge/sandbox'],
    ['d31', '/seller/customers'], ['d33', '/seller/tasks'], ['d35', '/seller/customer-io'],
    ['d23', '/seller/dialogs/mine'], ['d101', '/seller/integrations/catalog'], ['d104', '/seller/integrations/stock'],
    ['d51', '/seller/scenario/fields'],
  ] as const;
  const results: Record<string, unknown> = {};
  const findings: unknown[] = [];
  await mkdir('.local/screenshots/SP-E9-01G', { recursive: true });
  for (const [name, path] of paths) {
    const response = await page.goto(`${origin}${path}`);
    const status = response?.status();
    results[name] = { path, status };
    expect(status, path).toBe(200);
    await expect(page).toHaveURL(`${origin}${path}`);
    await expect(page.locator('h1').first()).toBeVisible();
    await page.addScriptTag({ path: 'node_modules/axe-core/axe.min.js' });
    const violations: Record<number, unknown> = {};
    results[name] = { path, status, violations };
    for (const width of [1440, 1024, 390]) {
      await page.setViewportSize({ width, height: 900 });
      violations[width] = await page.evaluate(async () => {
        const axe = (window as unknown as { axe: { run: (root: Document, options: object) => Promise<{ violations: { id: string; impact: string; nodes: { target: unknown[] }[] }[] }> } }).axe;
        return (await axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21aa'] } })).violations.filter(v => v.impact === 'critical' || v.impact === 'serious').map(v => ({ id: v.id, impact: v.impact, nodes: v.nodes.map(node => node.target) }));
      });
      findings.push(...(violations[width] as unknown[]));
      await page.screenshot({ path: `.local/screenshots/SP-E9-01G/populated-${name}-${width}.png`, fullPage: true, animations: 'disabled' });
    }
  }
  await writeFile('.local/screenshots/SP-E9-01G/populated-status.json', JSON.stringify(results, null, 2));
  expect(findings).toEqual([]);
});
