import { expect, test } from '@playwright/test';

test.use({ launchOptions: process.env.E502_CHROMIUM_PATH ? { executablePath: process.env.E502_CHROMIUM_PATH } : {} });

test('сессия, согласие и недоступный runtime', async ({ page }) => {
  const token = 't'.repeat(64);
  let starts = 0;
  let messages = 0;
  await page.route('**/widget/config?**', route => route.fulfill({ json: { appearance: { position: 'right', title: 'Напишите нам', color: '#245c41' }, behavior: { collect_contact: true, consent_url: 'https://example.test/privacy', invitation: 'Здравствуйте!' } } }));
  await page.route('**/widget/session?**', route => { starts++; return route.fulfill({ status: 201, json: { token } }); });
  await page.route('**/widget/message?**', route => { messages++; expect(route.request().postDataJSON().token).toBe(token); return route.fulfill({ status: 503, json: { message: 'Чат временно недоступен.' } }); });
  await page.route('**/widget/contact?**', route => { const body = route.request().postDataJSON(); expect(body.session_token).toBe(token); expect(body.consent).toBe(true); return route.fulfill({ status: 201, json: { ok: true } }); });
  await page.goto(`${process.env.E502_FIXTURE_ORIGIN ?? 'http://127.0.0.1:5274'}/tests/Browser/E5-02/widget-session-fixture.html`);
  await page.getByRole('button', { name: 'Напишите нам' }).click();
  await page.getByLabel('Ваше сообщение').fill('Здравствуйте');
  await page.getByRole('button', { name: 'Отправить', exact: true }).click();
  await expect(page.getByText('Чат временно недоступен. Попробуйте позже.')).toBeVisible();
  await page.getByRole('button', { name: 'Отправить', exact: true }).click();
  await expect.poll(() => messages).toBe(2);
  await page.getByRole('checkbox').check();
  await page.getByLabel('Электронная почта').fill('client@example.test');
  await page.getByRole('button', { name: 'Оставить контакт' }).click();
  await expect(page.getByText('Спасибо! Контакт сохранён.')).toBeVisible();
  expect(starts).toBe(1);
});
