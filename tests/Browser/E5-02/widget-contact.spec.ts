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


