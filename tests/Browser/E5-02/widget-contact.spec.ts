import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import path from 'node:path';
const source = readFileSync(path.resolve('resources/widget/widget.js'), 'utf8');
const key = 'a'.repeat(64);
test('consent before contact, timer and repeat suppression', async ({ page }) => {
  let submitted = false;
  await page.route('http://site.test/**', route => route.fulfill({status:200,contentType:'text/html; charset=utf-8',body:`<html><body><div style="height:2400px">Страница</div><script src="https://widget.example/widget.js?key=${key}"></script></body></html>`}));
  await page.route('https://widget.example/**', async route => {
    const url = new URL(route.request().url());
    if (url.pathname === '/widget.js') return route.fulfill({status:200,contentType:'application/javascript; charset=utf-8',body:source});
    if (url.pathname === '/widget/config') return route.fulfill({status:200,headers:{'Access-Control-Allow-Origin':'*'},contentType:'application/json',body:JSON.stringify({appearance:{title:'Связаться',color:'#225544',position:'right'},behavior:{delay_seconds:1,scroll_percent:50,invitation:'Нужна помощь?',collect_contact:true,consent_url:'https://site.test/privacy',repeat_hours:24}})});
    if (route.request().method() === 'OPTIONS') return route.fulfill({status:204,headers:{'Access-Control-Allow-Origin':'*','Access-Control-Allow-Headers':'content-type','Access-Control-Allow-Methods':'POST, OPTIONS'}});
    submitted = route.request().postDataJSON().consent === true;
    return route.fulfill({status:201,headers:{'Access-Control-Allow-Origin':'*'},contentType:'application/json',body:'{"ok":true}'});
  });
  await page.setViewportSize({width:1440,height:1000});
  await page.goto('http://site.test/catalog');
  const host = page.locator('#souzpir-widget'); await expect(host.getByText('Нужна помощь?')).toBeVisible({timeout:5000});
  await expect(host.getByLabel('Электронная почта')).toBeHidden();
  await host.getByRole('checkbox').check(); await expect(host.getByLabel('Электронная почта')).toBeVisible();
  await host.getByRole('button',{name:'Оставить контакт'}).click(); await expect(host.getByText('Укажите почту или телефон и подтвердите согласие.')).toBeVisible();
  if (process.env.WIDGET_SCREENSHOT) await page.screenshot({path:process.env.WIDGET_SCREENSHOT});
  await host.getByLabel('Электронная почта').fill('client@example.com'); await host.getByRole('button',{name:'Оставить контакт'}).click();
  await expect(host.getByText('Спасибо! Контакт сохранён.')).toBeVisible(); expect(submitted).toBe(true);
  await page.reload(); await page.waitForTimeout(1200); await expect(host.getByText('Нужна помощь?')).toBeHidden();
  await page.evaluate(() => localStorage.clear()); await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
  await expect(host.getByText('Нужна помощь?')).toBeVisible();
});



test('appearance, excluded pages and brand working hours are applied', async ({ page }) => {
  const data = { appearance: { title: 'Написать', color: '#225544', position: 'left', offset_side: 44, offset_bottom: 35,
    shape: 'rounded', icon: 'bot', window_title: 'Наш консультант', theme: 'dark', hide_mobile: false },
    behavior: { excluded_paths: ['/private*'], work_days: [1], work_start: '09:00', work_end: '18:00', offhours_text: 'Сейчас не работаем', delay_seconds: 1 }, timezone: 'Europe/Moscow' };
  await page.clock.install({ time: new Date('2026-11-02T19:00:00Z') });
  await page.route('http://site.test/**', route => route.fulfill({ status: 200, contentType: 'text/html', body: `<html><body><script src="https://widget.example/widget.js?key=${key}"></script></body></html>` }));
  await page.route('https://widget.example/**', route => route.request().url().includes('/widget.js')
    ? route.fulfill({ status: 200, contentType: 'application/javascript; charset=utf-8', body: source })
    : route.fulfill({ status: 200, headers: { 'Access-Control-Allow-Origin': '*' }, contentType: 'application/json', body: JSON.stringify(data) }));
  await page.goto('http://site.test/private/orders');
  await expect(page.locator('#souzpir-widget')).toHaveCount(0);
  await page.goto('http://site.test/catalog');
  const host = page.locator('#souzpir-widget');
  await expect(host).toBeVisible();
  await expect(host).toHaveCSS('left', '44px');
  await expect(host).toHaveCSS('bottom', '35px');
  await host.getByRole('button', { name: /Написать/ }).click();
  await expect(host.getByText('Наш консультант')).toBeVisible();
  await expect(host.getByText('Сейчас не работаем')).toBeVisible();
  await expect(host.locator('.panel')).toHaveCSS('background-color', 'rgb(23, 36, 58)');
  data.appearance.hide_mobile = true;
  await page.setViewportSize({ width: 390, height: 844 });
  await page.reload();
  await expect(page.locator('#souzpir-widget')).toHaveCount(0);
});
test('required contact form stays visible and configured consent text is shown', async ({ page }) => {
  const data = { appearance: { title: 'Написать', color: '#225544', position: 'right' }, behavior: {
    collect_contact: true, contact_mode: 'required', consent_url: 'https://site.test/privacy', consent_text: 'Разрешаю обработку данных' } };
  await page.route('http://site.test/**', route => route.fulfill({ status: 200, contentType: 'text/html', body: `<html><body><script src="https://widget.example/widget.js?key=${key}"></script></body></html>` }));
  await page.route('https://widget.example/**', route => route.request().url().includes('/widget.js')
    ? route.fulfill({ status: 200, contentType: 'application/javascript; charset=utf-8', body: source })
    : route.fulfill({ status: 200, headers: { 'Access-Control-Allow-Origin': '*' }, contentType: 'application/json', body: JSON.stringify(data) }));
  await page.goto('http://site.test/catalog');
  const host = page.locator('#souzpir-widget');
  await host.getByRole('button', { name: /Написать/ }).click();
  await expect(host.getByLabel('Электронная почта')).toBeVisible();
  await expect(host.getByText('Разрешаю обработку данных', { exact: false })).toBeVisible();
  await host.getByRole('checkbox').check();
  await host.getByRole('button', { name: 'Оставить контакт' }).click();
  await expect(host.getByText('Укажите почту или телефон и подтвердите согласие.')).toBeVisible();
});

test('weekday settings use Monday zero and overnight shift remains open', async ({ page }) => {
  const data = { appearance: { title: 'Написать', color: '#225544', position: 'right' }, behavior: {
    work_days: [0], work_start: '22:00', work_end: '02:00', offhours_text: 'Сейчас не работаем', delay_seconds: 1,
    invitation: 'Добрый вечер' }, timezone: 'Europe/Moscow' };
  await page.clock.install({ time: new Date('2026-11-02T21:30:00Z') });
  await page.route('http://site.test/**', route => route.fulfill({ status: 200, contentType: 'text/html', body: `<html><body><script src="https://widget.example/widget.js?key=${key}"></script></body></html>` }));
  await page.route('https://widget.example/**', route => route.request().url().includes('/widget.js')
    ? route.fulfill({ status: 200, contentType: 'application/javascript; charset=utf-8', body: source })
    : route.fulfill({ status: 200, headers: { 'Access-Control-Allow-Origin': '*' }, contentType: 'application/json', body: JSON.stringify(data) }));
  await page.goto('http://site.test/catalog');
  const host = page.locator('#souzpir-widget');
  await expect(host).toBeVisible();
  await page.clock.fastForward(1500);
  await expect(host.getByText('Добрый вечер')).toBeVisible();
});

test('message limit from settings blocks sending at the boundary', async ({ page }) => {
  const token = 'm'.repeat(64);
  let messages = 0;
  await page.route('http://site.test/**', route => route.fulfill({ status: 200, contentType: 'text/html', body: `<html><body><script src="https://widget.example/widget.js?key=${key}"></script></body></html>` }));
  await page.route('https://widget.example/**', route => {
    const url = new URL(route.request().url());
    if (url.pathname === '/widget.js') return route.fulfill({ status: 200, contentType: 'application/javascript; charset=utf-8', body: source });
    if (url.pathname === '/widget/config') return route.fulfill({ status: 200, headers: { 'Access-Control-Allow-Origin': '*' }, contentType: 'application/json', body: JSON.stringify({ appearance: { title: 'Напишите нам', color: '#245c41', position: 'right' }, behavior: { max_messages: 2 } }) });
    if (route.request().method() === 'OPTIONS') return route.fulfill({ status: 204, headers: { 'Access-Control-Allow-Origin': '*', 'Access-Control-Allow-Headers': 'content-type', 'Access-Control-Allow-Methods': 'POST, OPTIONS' } });
    if (url.pathname === '/widget/session') return route.fulfill({ status: 201, headers: { 'Access-Control-Allow-Origin': '*' }, contentType: 'application/json', body: JSON.stringify({ token }) });
    messages++;
    return route.fulfill({ status: 201, headers: { 'Access-Control-Allow-Origin': '*' }, contentType: 'application/json', body: '{"ok":true}' });
  });
  await page.goto('http://site.test/catalog');
  const host = page.locator('#souzpir-widget');
  await host.getByRole('button', { name: 'Напишите нам' }).click();
  await host.getByLabel('Ваше сообщение').fill('Первое сообщение');
  await host.getByRole('button', { name: 'Отправить', exact: true }).click();
  await expect(host.getByText('Сообщение отправлено.')).toBeVisible();
  await host.getByLabel('Ваше сообщение').fill('Второе сообщение');
  await host.getByRole('button', { name: 'Отправить', exact: true }).click();
  await expect(host.getByText('Лимит сообщений исчерпан. Напишите нам другим способом.')).toBeVisible();
  await expect(host.getByLabel('Ваше сообщение')).toBeDisabled();
  await expect(host.getByRole('button', { name: 'Отправить', exact: true })).toBeDisabled();
  await expect.poll(() => messages).toBe(2);
});
