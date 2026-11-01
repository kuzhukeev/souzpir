import { expect, test } from '@playwright/test';
import { readFile } from 'node:fs/promises';

test('навигация будущих каналов доступна с клавиатуры во всех темах и ширинах', async ({ page }) => {
  test.setTimeout(120000);
  const brands = JSON.parse(await readFile('.local/runtime/ui05-fixture.json', 'utf8')) as Record<string, string>;
  await page.goto('/login');
  await page.getByLabel('Рабочая почта').fill('owner-ui05@example.test');
  await page.getByLabel('Пароль', { exact: true }).fill('test-only-password-123');
  await page.getByRole('button', { name: 'Войти', exact: true }).click();
  await expect(page).toHaveURL(/\/auth\/ready$/);
  for (const theme of ['neutral', 'pyro', 'confectionery']) {
    await page.goto('/seller/integrations/planned');
    await expect(page.locator('main h1')).toBeVisible();
    if (await page.locator('html').getAttribute('data-theme') !== theme) {
      const status = await page.evaluate(async (brandId) => {
        const cookie = document.cookie.split('; ').find((value) => value.startsWith('XSRF-TOKEN='));
        if (!cookie) throw new Error('Missing CSRF');
        return (await fetch('/brand/switch', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(cookie.slice(11)) }, body: JSON.stringify({ brand_id: brandId }) })).status;
      }, brands[theme]);
      expect(status).toBe(200);
      await page.goto('/seller/integrations/planned');
    }
    await expect(page.locator('html')).toHaveAttribute('data-theme', theme);
    for (const width of [390, 1024, 1440]) {
      await page.setViewportSize({ width, height: 900 });
      for (const [label, path] of [['ВКонтакте · Скоро', '/seller/channels/vk'], ['WhatsApp · Скоро', '/seller/channels/whatsapp'], ['Почта и календарь · Скоро', '/seller/integrations/calendar']]) {
        const link = page.getByRole('link', { name: label, exact: true });
        expect(await link.evaluate((element) => getComputedStyle(element).cursor)).toBe('pointer');
        await link.focus();
        await page.keyboard.press('Tab');
        await page.keyboard.press('Shift+Tab');
        await expect(link).toBeFocused();
        expect(await link.evaluate((element) => getComputedStyle(element).outlineStyle)).not.toBe('none');
        if (theme === 'neutral' && width === 1440 && label.startsWith('ВКонтакте')) {
          await page.screenshot({ path: '.local/screenshots/sp-ui-05/planned-1440.png', fullPage: true });
        }
        await page.keyboard.press('Enter');
        await expect(page).toHaveURL(new RegExp(path+'$'));
        const back = page.getByRole('link', { name: 'Другие будущие интеграции' });
        expect(await back.evaluate((element) => getComputedStyle(element).cursor)).toBe('pointer');
        await page.keyboard.press('Tab');
        await back.focus();
        await expect(back).toBeFocused();
        expect(await back.evaluate((element) => getComputedStyle(element).outlineStyle)).not.toBe('none');
        await page.keyboard.press('Enter');
        await expect(page).toHaveURL(/\/seller\/integrations\/planned$/);
      }
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true);
    }
  }
});


