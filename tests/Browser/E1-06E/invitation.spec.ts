import { expect, test, type Page } from '@playwright/test';

// Playwright's automatic failure snapshot could otherwise serialize the one-time bearer.
process.env.PLAYWRIGHT_NO_COPY_PROMPT = '1';
test.skip(process.env.RUN_E106E_BROWSER !== '1', 'Requires an isolated synthetic E1-06E server.');
test.use({
  trace: 'off',
  screenshot: 'off',
  video: 'off',
  launchOptions: {
    executablePath: process.env.E106E_CHROME,
    args: ['--host-resolver-rules=MAP souzpir.local 127.0.0.1'],
  },
});
const origin = 'http://127.0.0.1:8231';
const assets = 'http://127.0.0.1:5178';
const localDomain = 'http://souzpir.local:8231';

async function login(page: Page, role: 'owner' | 'viewer', base = origin) {
  await page.goto(`${base}/login`);
  await page.getByLabel('Рабочая почта').fill(`${role}-e106e@example.test`);
  await page.getByLabel('Пароль', { exact: true }).fill('test-only-password-123');
  await page.getByRole('button', { name: 'Войти', exact: true }).click();
  await expect(page).toHaveURL(/auth\/ready/);
  await page.goto(`${base}/_e106e`);
  await expect(page.getByRole('button', { name: 'Открыть форму' })).toBeVisible();
}

async function openAndConfirm(page: Page, email: string) {
  await page.getByRole('button', { name: 'Открыть форму' }).click();
  await page.getByLabel('Электронная почта').fill(email);
  await page.getByRole('button', { name: 'Продолжить' }).click();
  await expect(page.getByText('Письмо автоматически не отправляется.')).toBeVisible();
  await page.getByRole('button', { name: 'Подтвердить выпуск' }).click();
}

async function count(page: Page, email: string): Promise<number> {
  const response = await page.request.get(
    `${origin}/_e106e/count?email=${encodeURIComponent(email)}`,
  );
  expect(response.status()).toBe(200);
  return (await response.json()).count as number;
}

test.beforeEach(async ({ page }) => {
  await page.route('**/*', (route) =>
    [origin, assets, localDomain].includes(new URL(route.request().url()).origin)
      ? route.continue()
      : route.abort('blockedbyclient'),
  );
});

test('HTTP souzpir.local работает без secure-context и даёт ручное копирование', async ({
  page,
}) => {
  await page.route(`${assets}/**`, async (route) => {
    const response = await route.fetch();
    await route.fulfill({
      response,
      headers: { ...response.headers(), 'access-control-allow-origin': localDomain },
    });
  });
  await login(page, 'owner', localDomain);
  expect(await page.evaluate(() => window.isSecureContext)).toBe(false);
  const email = `e106e-local-${Date.now()}@example.test`;
  await openAndConfirm(page, email);
  const field = page.getByLabel('Ссылка приглашения');
  await expect(field).toBeVisible();
  await page.getByRole('button', { name: 'Копировать ссылку' }).click();
  await expect(page.getByText('Выделите ссылку и скопируйте вручную.')).toBeVisible();
  expect(await count(page, email)).toBe(1);
});

test('роль менеджера и выбранные бренды передаются настоящему серверу', async ({ page }) => {
  const email = `e106e-manager-${Date.now()}@example.test`;
  await login(page, 'owner');
  await page.getByRole('button', { name: 'Открыть форму' }).click();
  await page.getByLabel('Электронная почта').fill(email);
  await page.getByRole('radio', { name: /^Менеджер/ }).check();
  const submitted = page.waitForRequest(
    (request) => request.method() === 'POST' && request.url() === `${origin}/team/invitations`,
  );
  await page.getByRole('button', { name: 'Продолжить' }).click();
  await expect(page.getByText('Роль: Менеджер')).toBeVisible();
  await page.getByRole('button', { name: 'Подтвердить выпуск' }).click();
  const body = (await submitted).postDataJSON() as {
    role: string;
    sales_manager: boolean;
    brand_ids: string[];
  };
  expect(body.role).toBe('member');
  expect(body.sales_manager).toBe(true);
  expect(body.brand_ids).toHaveLength(1);
  await expect(page.getByLabel('Ссылка приглашения')).toBeVisible();
  expect(await count(page, email)).toBe(1);
});

test('реальный выпуск, однократный показ и ручное копирование по HTTP', async ({ page }) => {
  const email = `e106e-real-${Date.now()}@example.test`;
  await login(page, 'owner');
  expect(await count(page, email)).toBe(0);
  await page.evaluate(() =>
    Object.defineProperty(navigator, 'clipboard', { configurable: true, value: undefined }),
  );
  const posted = page.waitForResponse(
    (response) =>
      response.request().method() === 'POST' && response.url() === `${origin}/team/invitations`,
  );
  await openAndConfirm(page, email);
  expect((await posted).status()).toBe(201);
  const field = page.getByLabel('Ссылка приглашения');
  await expect(field).toBeVisible();
  const link = await field.inputValue();
  expect(/^http:\/\/127\.0\.0\.1:8231\/invitations\/open#[A-Za-z0-9]{64}$/.test(link)).toBe(true);
  expect(await count(page, email)).toBe(1);
  const secret = link.split('#')[1];
  expect(
    await page.evaluate(
      (value) =>
        JSON.stringify({
          url: location.href,
          history: history.state,
          local: localStorage,
          session: sessionStorage,
        }).includes(value),
      secret,
    ),
  ).toBe(false);
  await page.getByRole('button', { name: 'Копировать ссылку' }).click();
  await expect(page.getByText('Выделите ссылку и скопируйте вручную.')).toBeVisible();
  expect(
    await field.evaluate(
      (element) =>
        (element as HTMLTextAreaElement).selectionEnd -
        (element as HTMLTextAreaElement).selectionStart,
    ),
  ).toBe(link.length);
  await page.getByRole('button', { name: 'Закрыть' }).click();
  await expect(field).toHaveCount(0);
  await page.getByRole('button', { name: 'Открыть форму' }).click();
  await expect(page.getByLabel('Ссылка приглашения')).toHaveCount(0);
  const guestContext = await page.context().browser()!.newContext();
  try {
    const guest = await guestContext.newPage();
    await guest.route('**/*', (route) => new URL(route.request().url()).origin === origin ? route.continue() : route.abort('blockedbyclient'));
    await guest.goto(`${origin}/invitations/open`);
    const statuses = await guest.evaluate(async (bearer) => {
      const epoch = document.documentElement.outerHTML.match(/epoch:"([a-f0-9]{64})"/)?.[1];
      const cookie = document.cookie.split('; ').find((part) => part.startsWith('XSRF-TOKEN='));
      if (!epoch || !cookie) return [];
      const send = (sequence: number) => fetch('/invitations/exchange', {
        method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(cookie.slice(11)) },
        body: JSON.stringify({ token: bearer, epoch, sequence }),
      });
      return [(await send(1)).status, (await send(2)).status];
    }, secret);
    expect(statuses).toEqual([200, 404]);
  } finally { await guestContext.close(); }
});

test('потерянный ответ не запускает повтор и требует нового подтверждения', async ({ page }) => {
  const email = `e106e-lost-${Date.now()}@example.test`;
  await login(page, 'owner');
  let posts = 0;
  await page.route('**/team/invitations', async (route) => {
    if (route.request().method() !== 'POST') return route.continue();
    posts += 1;
    if (posts === 1) {
      await route.fetch();
      await route.abort('failed');
    } else await route.continue();
  });
  await openAndConfirm(page, email);
  await expect(page.getByText(/Результат неизвестен/)).toBeVisible();
  expect(posts).toBe(1);
  expect(await count(page, email)).toBe(1);
  await page.getByRole('button', { name: 'Понятно, создать ещё' }).click();
  expect(posts).toBe(1);
  await page.getByRole('button', { name: 'Продолжить' }).click();
  expect(posts).toBe(1);
  await page.getByRole('button', { name: 'Подтвердить выпуск' }).click();
  await expect(page.getByLabel('Ссылка приглашения')).toBeVisible();
  expect(posts).toBe(2);
  expect(await count(page, email)).toBe(2);
});

test('наблюдатель не может выпустить приглашение', async ({ page }) => {
  await login(page, 'viewer');
  await page.getByRole('button', { name: 'Открыть форму' }).click();
  await expect(
    page.getByText('Контекст изменился. Закройте окно и проверьте доступ.'),
  ).toBeVisible();
  await expect(page.getByLabel('Электронная почта')).toHaveCount(0);
  const status = await page.evaluate(async () => {
    const token = document.cookie.split('; ').find((part) => part.startsWith('XSRF-TOKEN='));
    const team = await fetch('/team', {
      headers: { 'X-Inertia': 'true', 'X-Inertia-Version': document.querySelector('main')?.dataset.inertiaVersion ?? '', Accept: 'text/html, application/xhtml+xml' },
    });
    const page = (await team.json()) as {
      props: { brand: { id: string }; teamBrandRevision: string };
    };
    const response = await fetch('/team/invitations', {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-XSRF-TOKEN': decodeURIComponent(token!.slice(11)),
        'X-Brand-Revision': page.props.teamBrandRevision,
      },
      body: JSON.stringify({
        attempt_key: 'a'.repeat(64),
        email: 'viewer-e106e-denied@example.test',
        role: 'viewer',
        sales_manager: false,
        brand_ids: [page.props.brand.id],
      }),
    });
    return response.status;
  });
  expect(status).toBe(403);
  expect(await count(page, 'viewer-e106e-denied@example.test')).toBe(0);
});

test('смена контекста скрывает секрет и игнорирует поздний ответ', async ({ page }) => {
  const email = `e106e-late-${Date.now()}@example.test`;
  await login(page, 'owner');
  let unblock!: () => void;
  const gate = new Promise<void>((resolve) => {
    unblock = resolve;
  });
  let entered!: () => void;
  const received = new Promise<void>((resolve) => {
    entered = resolve;
  });
  await page.route('**/team/invitations', async (route) => {
    if (route.request().method() !== 'POST') return route.continue();
    const response = await route.fetch();
    entered();
    await gate;
    try {
      await route.fulfill({ response });
    } catch {
      /* The changed context aborts the request. */
    }
  });
  await openAndConfirm(page, email);
  await received;
  await page.evaluate(() => {
    const button = [...document.querySelectorAll('button')].find(
      (item) => item.textContent === 'Сменить контекст',
    );
    button?.click();
  });
  await expect(page.getByText(/Результат неизвестен/)).toBeVisible();
  unblock();
  await expect(page.getByLabel('Ссылка приглашения')).toHaveCount(0);
  expect(await count(page, email)).toBe(1);
});

test('сервер отклоняет чужой бренд', async ({ page }) => {
  await login(page, 'owner');
  const email = `e106e-denied-${Date.now()}@example.test`;
  const status = await page.evaluate(async (targetEmail) => {
    const cookie = document.cookie.split('; ').find((part) => part.startsWith('XSRF-TOKEN='));
    const team = await fetch('/team', {
      headers: { 'X-Inertia': 'true', 'X-Inertia-Version': document.querySelector('main')?.dataset.inertiaVersion ?? '', Accept: 'text/html, application/xhtml+xml' },
    });
    const result = (await team.json()) as { props: { teamBrandRevision: string } };
    const response = await fetch('/team/invitations', {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-XSRF-TOKEN': decodeURIComponent(cookie!.slice(11)),
        'X-Brand-Revision': result.props.teamBrandRevision,
      },
      body: JSON.stringify({
        attempt_key: 'b'.repeat(64),
        email: targetEmail,
        role: 'viewer',
        sales_manager: false,
        brand_ids: ['01J00000000000000000000001'],
      }),
    });
    return response.status;
  }, email);
  expect(status).toBe(404);
  expect(await count(page, email)).toBe(0);
});

test('закрытие во время запроса предупреждает о неизвестном результате при повторном открытии', async ({ page }) => {
  const email = `e106e-close-${Date.now()}@example.test`;
  await login(page, 'owner');
  let release!: () => void;
  const gate = new Promise<void>((resolve) => { release = resolve; });
  let entered!: () => void;
  const received = new Promise<void>((resolve) => { entered = resolve; });
  await page.route('**/team/invitations', async (route) => {
    if (route.request().method() !== 'POST') return route.continue();
    const response = await route.fetch();
    entered();
    await gate;
    try { await route.fulfill({ response }); } catch { /* The closed dialog aborts the request. */ }
  });
  await openAndConfirm(page, email);
  await received;
  await page.getByRole('button', { name: 'Закрыть' }).click();
  release();
  await page.getByRole('button', { name: 'Открыть форму' }).click();
  await expect(page.getByText(/Результат неизвестен/)).toBeVisible();
  await expect(page.getByLabel('Ссылка приглашения')).toHaveCount(0);
  expect(await count(page, email)).toBe(1);
});

test('настоящая смена бренда в другой вкладке скрывает ранее показанную ссылку', async ({
  page,
}) => {
  const email = `e106e-switch-${Date.now()}@example.test`;
  await login(page, 'owner');
  const current = await page.evaluate(async () => {
    const response = await fetch('/team', {
      headers: { 'X-Inertia': 'true', 'X-Inertia-Version': document.querySelector('main')?.dataset.inertiaVersion ?? '', Accept: 'text/html, application/xhtml+xml' },
    });
    const result = (await response.json()) as {
      props: { brand: { id: string }; filterBrands: { id: string; name: string }[] };
    };
    return {
      id: result.props.brand.id,
      other: result.props.filterBrands.find((brand) => brand.id !== result.props.brand.id)!,
    };
  });
  await openAndConfirm(page, email);
  await expect(page.getByLabel('Ссылка приглашения')).toBeVisible();
  const other = await page.context().newPage();
  await other.route('**/*', (route) =>
    [origin, assets].includes(new URL(route.request().url()).origin)
      ? route.continue()
      : route.abort('blockedbyclient'),
  );
  await other.goto(`${origin}/brands/${current.id}/settings`);
  await other.getByRole('combobox', { name: 'Выбрать бренд' }).click();
  await other.getByRole('option', { name: current.other.name }).click();
  await expect(other).toHaveURL(/auth\/ready/);
  await expect(page.getByLabel('Ссылка приглашения')).toHaveCount(0);
  expect(await count(page, email)).toBe(1);
  await other.close();
});

test('отзыв активной серверной сессии убирает уже показанную ссылку', async ({ page }) => {
  const email = `e106e-revoked-${Date.now()}@example.test`;
  await login(page, 'owner');
  await openAndConfirm(page, email);
  await expect(page.getByLabel('Ссылка приглашения')).toBeVisible();
  await page.evaluate(async () => {
    const cookie = document.cookie.split('; ').find((part) => part.startsWith('XSRF-TOKEN='));
    await fetch('/logout', {
      method: 'POST',
      headers: { 'X-XSRF-TOKEN': decodeURIComponent(cookie!.slice(11)) },
      credentials: 'same-origin',
    });
  });
  await expect(page.getByLabel('Ссылка приглашения')).toHaveCount(0, { timeout: 12_000 });
  await expect(page.getByText(/Контекст изменился или доступ больше не подтверждён/)).toBeVisible();
  expect(await count(page, email)).toBe(1);
});

for (const sample of [1440, 1024, 390].flatMap((width) =>
  ['neutral', 'pyro', 'confectionery'].map((theme) => ({ width, theme })),
)) {
  test(`форма доступна на ${sample.width}px, тема ${sample.theme}`, async ({ page }) => {
    await page.setViewportSize({ width: sample.width, height: 900 });
    await login(page, 'owner');
    await page.evaluate((theme) => {
      document.documentElement.dataset.theme = theme;
    }, sample.theme);
    await page.getByRole('button', { name: 'Открыть форму' }).click();
    const dialog = page.getByRole('dialog', { name: 'Пригласить участника' });
    await expect(dialog).toBeVisible();
    const role = page.getByRole('radio', { name: /^Менеджер/ });
    await role.focus();
    await page.keyboard.press('Space');
    await expect(role).toBeChecked();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
      true,
    );
    const undersized = await dialog.evaluate((root) =>
      [...root.querySelectorAll<HTMLElement>('*')]
        .filter(
          (node) =>
            [...node.childNodes].some(
              (child) => child.nodeType === Node.TEXT_NODE && Boolean(child.textContent?.trim()),
            ) &&
            node.getClientRects().length > 0 &&
            Number.parseFloat(getComputedStyle(node).fontSize) < 13,
        )
        .map((node) => node.textContent?.trim()),
    );
    expect(undersized).toEqual([]);
    await page.addScriptTag({ path: 'node_modules/axe-core/axe.min.js' });
    const violations = await dialog.evaluate(async (root) => {
      const axe = (
        window as unknown as {
          axe: {
            run: (element: Element) => Promise<{ violations: { id: string; impact: string }[] }>;
          };
        }
      ).axe;
      return (await axe.run(root)).violations
        .filter((issue) => issue.impact === 'critical' || issue.impact === 'serious')
        .map((issue) => issue.id);
    });
    expect(violations).toEqual([]);
    await dialog.screenshot({
      path: `.local/screenshots/e1-06e/form-${sample.width}-${sample.theme}.png`,
    });
    await page.keyboard.press('Escape');
    await expect(dialog).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Открыть форму' })).toBeFocused();
  });
}
