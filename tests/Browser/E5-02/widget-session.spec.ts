import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { mkdir } from 'node:fs/promises';
import path from 'node:path';

const source = readFileSync(path.resolve('resources/widget/widget.js'), 'utf8');
const key = 'b'.repeat(64);

test('сессия, ход, повтор и недоступный runtime', async ({ page }) => {
  const token = 't'.repeat(64);
  let starts = 0;
  let messages = 0;
  let available = false;
  await page.route('http://site.test/**', route => route.fulfill({ status: 200, contentType: 'text/html', body: `<html><body><script src="https://widget.example/widget.js?key=${key}"></script></body></html>` }));
  await page.route('https://widget.example/**', route => {
    const url = new URL(route.request().url());
    if (url.pathname === '/widget.js') return route.fulfill({ status: 200, contentType: 'application/javascript; charset=utf-8', body: source });
    if (url.pathname === '/widget/config') return route.fulfill({ status: 200, headers: { 'Access-Control-Allow-Origin': '*' }, contentType: 'application/json', body: JSON.stringify({ appearance: { position: 'right', title: 'Напишите нам', color: '#245c41' }, behavior: { collect_contact: true, consent_url: 'https://example.test/privacy', invitation: 'Здравствуйте!' } }) });
    if (route.request().method() === 'OPTIONS') return route.fulfill({ status: 204, headers: { 'Access-Control-Allow-Origin': '*', 'Access-Control-Allow-Headers': 'content-type', 'Access-Control-Allow-Methods': 'POST, OPTIONS' } });
    if (url.pathname === '/widget/session') { starts++; return route.fulfill({ status: 201, headers: { 'Access-Control-Allow-Origin': '*' }, contentType: 'application/json', body: JSON.stringify({ token }) }); }
    if (url.pathname === '/widget/message') {
      messages++;
      expect(route.request().postDataJSON().token).toBe(token);

      return available
        ? route.fulfill({ status: 201, headers: { 'Access-Control-Allow-Origin': '*' }, contentType: 'application/json', body: JSON.stringify(messages === 2 ? { ok: true, reply: 'Здравствуйте! Чем помочь?' } : { ok: true }) })
        : route.fulfill({ status: 503, headers: { 'Access-Control-Allow-Origin': '*' }, contentType: 'application/json', body: '{"message":"Чат временно недоступен."}' });
    }
    const body = route.request().postDataJSON();
    expect(body.session_token).toBe(token);
    expect(body.consent).toBe(true);

    return route.fulfill({ status: 201, headers: { 'Access-Control-Allow-Origin': '*' }, contentType: 'application/json', body: '{"ok":true}' });
  });
  await page.goto('http://site.test/catalog');
  const host = page.locator('#souzpir-widget');
  await host.getByRole('button', { name: 'Напишите нам' }).click();
  await host.getByLabel('Ваше сообщение').fill('Здравствуйте');
  await host.getByRole('button', { name: 'Отправить', exact: true }).click();
  await expect(host.getByText('Чат временно недоступен. Попробуйте позже.')).toBeVisible();
  available = true;
  await host.getByRole('button', { name: 'Отправить', exact: true }).click();
  await expect(host.getByText('Сообщение отправлено.')).toBeVisible();
  await expect(host.getByText('Здравствуйте! Чем помочь?')).toBeVisible();
  await page.setViewportSize({ width: 1440, height: 900 });
  await mkdir('.local/screenshots/sp-e5-02c', { recursive: true });
  await page.screenshot({ path: '.local/screenshots/sp-e5-02c/widget-chat-1440.png' });
  await host.getByLabel('Ваше сообщение').fill('Повтор');
  await host.getByRole('button', { name: 'Отправить', exact: true }).click();
  await expect.poll(() => messages).toBe(3);
  await host.getByRole('checkbox').check();
  await host.getByLabel('Электронная почта').fill('client@example.test');
  await host.getByRole('button', { name: 'Оставить контакт' }).click();
  await expect(host.getByText('Спасибо! Контакт сохранён.')).toBeVisible();
  expect(starts).toBe(1);
});
