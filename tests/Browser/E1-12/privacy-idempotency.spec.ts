import { expect, test, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { createServer, type Socket } from 'node:net';

// The server must be in local mode, use the isolated test DB, and bind to loopback only.
// The browser deliberately uses the insecure souzpir.local origin used in development.
test.skip(
  process.env.RUN_SUPPORT_E2E !== '1' || process.env.RUN_SUPPORT_PRIVACY_E2E !== '1',
  'Requires the isolated SP-E1-12 fixture, loopback server, and fake SMTP.',
);
const port = Number(process.env.SUPPORT_PRIVACY_HTTP_PORT || '8212');
const smtpPort = Number(process.env.SUPPORT_PRIVACY_SMTP_PORT || '18212');
const origin = `http://souzpir.local:${port}`;
test.use({
  baseURL: origin,
  launchOptions: {
    executablePath: process.env.SUPPORT_CHROMIUM_BINARY || undefined,
    args: ['--no-proxy-server', '--host-resolver-rules=MAP souzpir.local 127.0.0.1'],
  },
});

const png = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j6GkAAAAASUVORK5CYII=',
  'base64',
);

// No transcript is retained. Drop the connection after receiving DATA, so the sender
// cannot tell whether the message was accepted and must never send it a second time.
function fakeSmtp() {
  let messages = 0;
  const sockets = new Set<Socket>();
  const server = createServer((socket) => {
    sockets.add(socket);
    socket.on('close', () => sockets.delete(socket));
    socket.on('error', () => {});
    socket.write('220 localhost fixture\r\n');
    let pending = '';
    let data = false;
    socket.on('data', (bytes) => {
      pending += bytes.toString('utf8');
      let boundary: number;
      while ((boundary = pending.indexOf('\r\n')) >= 0) {
        const line = pending.slice(0, boundary);
        pending = pending.slice(boundary + 2);
        if (data) {
          if (line === '.') {
            messages += 1;
            socket.destroy();
            return;
          }
          continue;
        }
        const command = line.split(' ', 1)[0].toUpperCase();
        if (command === 'EHLO' || command === 'HELO')
          socket.write('250-localhost\r\n250 8BITMIME\r\n');
        else if (command === 'DATA') {
          data = true;
          socket.write('354 End with dot\r\n');
        } else if (command === 'QUIT') socket.end('221 Bye\r\n');
        else socket.write('250 OK\r\n');
      }
    });
  });
  return {
    count: () => messages,
    start: () =>
      new Promise<void>((resolve, reject) => {
        server.once('error', reject);
        server.listen(smtpPort, '127.0.0.1', () => resolve());
      }),
    stop: () =>
      new Promise<void>((resolve, reject) => {
        for (const socket of sockets) socket.destroy();
        server.close((error) => (error ? reject(error) : resolve()));
      }),
  };
}

async function assertNoPrivateHistory(page: Page, body: string, filename: string) {
  const state = await page.evaluate(() => ({
    history: JSON.stringify(history.state) || '',
    session: JSON.stringify(Object.fromEntries(Object.entries(sessionStorage))),
  }));
  for (const value of [state.history, state.session]) {
    expect(value).not.toContain(body);
    expect(value).not.toContain(filename);
  }
}

test('private history and an uncertain retry keep one ticket and one local SMTP delivery', async ({
  page,
  context,
}) => {
  test.setTimeout(90_000);
  const smtp = fakeSmtp();
  await smtp.start();
  try {
    // Prevent browser traffic to every origin other than the dedicated local fixture.
    await context.route('**/*', (route) =>
      new URL(route.request().url()).origin === origin ? route.continue() : route.abort(),
    );
    const fixture = JSON.parse(readFileSync('.local/runtime/e1-12-browser.json', 'utf8')) as {
      owner_email: string;
    };
    await page.goto('/login');
    await expect(page.getByLabel('Рабочая почта')).toBeVisible();
    await page.getByLabel('Рабочая почта').fill(fixture.owner_email);
    await page.getByLabel('Пароль', { exact: true }).fill('test-only-password-123');
    await page.getByRole('button', { name: 'Войти', exact: true }).click();
    await expect(page).toHaveURL(/\/auth\/ready$/);
    await page.goto('/help/support');
    expect(await page.evaluate(() => window.isSecureContext)).toBe(false);

    const suffix = `${Date.now()}`;
    const body = `SYNTHETIC_SUPPORT_BODY_${suffix} — проверка приватности обращения.`;
    const filename = `SYNTHETIC_PRIVATE_FILENAME_${suffix}.png`;
    for (const label of ['Тема', 'Модуль']) {
      await page.getByRole('combobox', { name: label, exact: true }).click();
      await page.getByRole('option').first().click();
    }
    await page.getByLabel('Описание', { exact: true }).fill(body);
    await page
      .locator('input[type=file]')
      .setInputFiles({ name: filename, mimeType: 'image/png', buffer: png });

    const keys: string[] = [];
    const ticketIds: string[] = [];
    const statuses: number[] = [];
    await page.route('**/help/support', async (route) => {
      if (route.request().method() !== 'POST') return route.continue();
      const posted = route.request().postDataBuffer()?.toString('utf8') || '';
      const key = posted.match(/name="submission_key"\r\n\r\n([0-9A-HJKMNP-TV-Z]{26})/i)?.[1];
      if (!key) throw new Error('The support request has no idempotency key');
      keys.push(key);
      // Playwright's fetch may use system DNS, where this domain points to OSPanel.
      // Override the destination to loopback while preserving the development Host.
      const headers = await route.request().allHeaders();
      headers.host = `souzpir.local:${port}`;
      const response = await route.fetch({ url: `http://127.0.0.1:${port}/help/support`, headers });
      statuses.push(response.status());
      const result = (await response.json()) as { data?: { ticket?: { id?: string } } };
      ticketIds.push(result.data?.ticket?.id || '');
      if (keys.length === 1) await route.abort('failed');
      else await route.fulfill({ response });
    });

    const submit = page.getByRole('button', { name: 'Отправить обращение', exact: true });
    await submit.click();
    await expect(page.getByRole('alert')).toContainText('Не удалось подтвердить результат');
    await expect(page.getByLabel('Описание', { exact: true })).toHaveValue(body);
    expect(statuses).toEqual([201]);
    expect(smtp.count()).toBe(1);
    await assertNoPrivateHistory(page, body, filename);
    await submit.click();
    await expect.poll(() => statuses.length).toBe(2);
    expect(statuses).toEqual([201, 200]);
    expect(keys[1]).toBe(keys[0]);
    expect(ticketIds[0]).toMatch(/^[0-9A-HJKMNP-TV-Z]{26}$/i);
    expect(ticketIds[1]).toBe(ticketIds[0]);
    expect(smtp.count()).toBe(1);

    const php = process.env.SUPPORT_PHP_BINARY;
    if (!php) throw new Error('SUPPORT_PHP_BINARY must identify the local PHP executable');
    const inspection = JSON.parse(
      execFileSync(php, ['tests/Browser/E1-12/inspect-ticket.php', keys[0], ticketIds[0]], {
        encoding: 'utf8',
        env: {
          ...process.env,
          APP_ENV: 'testing',
          E112_FIXTURE_ENABLED: '1',
          E112_FIXTURE_ORG: '01J00000000000000000000112',
        },
      }),
    ) as { count: number; matching_ticket: number; delivery_status: string };
    expect(inspection).toEqual({ count: 1, matching_ticket: 1, delivery_status: 'uncertain' });

    const card = page.locator(`[data-ticket-id="${ticketIds[0]}"]`);
    await expect(card).toBeVisible();
    if ((await card.getAttribute('open')) === null) await card.locator('summary').click();
    await expect(card.getByText(body, { exact: true })).toBeVisible();
    await expect(card.getByText(filename, { exact: true })).toBeVisible();
    await assertNoPrivateHistory(page, body, filename);
    await page.goto('/help');
    await expect(page.locator('body')).not.toContainText(body);
    await expect(page.locator('body')).not.toContainText(filename);
    await assertNoPrivateHistory(page, body, filename);
    await page.goBack();
    await expect(card).toBeVisible();
    await assertNoPrivateHistory(page, body, filename);
    await page.goForward();
    await expect(page.locator('body')).not.toContainText(body);
    await expect(page.locator('body')).not.toContainText(filename);
    await assertNoPrivateHistory(page, body, filename);
    await page.goBack();
    await expect(card).toBeVisible();
    await assertNoPrivateHistory(page, body, filename);
  } finally {
    await smtp.stop();
  }
});
