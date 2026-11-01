import { expect, test } from '@playwright/test';

const fixture = 'http://127.0.0.1:5237/tests/Browser/SP-UI-05/fixture.html';
const links = [
  ['ВКонтакте · Скоро', '/seller/channels/vk'],
  ['WhatsApp · Скоро', '/seller/channels/whatsapp'],
  ['Почта и календарь · Скоро', '/seller/integrations/calendar'],
] as const;

for (const theme of ['neutral', 'pyro', 'confectionery']) {
  for (const width of [390, 1024, 1440]) {
    test(`будущие каналы: ссылки ${theme} ${width}`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`${fixture}?theme=${theme}&section=planned`);
      for (const [label, path] of links) {
        const link = page.getByRole('link', { name: label, exact: true });
        await expect(link).toHaveAttribute('href', path);
        expect(await link.evaluate((element) => getComputedStyle(element).cursor)).toBe('pointer');
        await link.focus();
        await expect(link).toBeFocused();
        expect(await link.evaluate((element) => getComputedStyle(element).outlineStyle)).not.toBe('none');
      }
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
      if (theme === 'neutral' && width === 1440) {
        await page.screenshot({ path: '.local/screenshots/sp-ui-05/planned-1440.png', fullPage: true });
      }
    });
  }
}

test('страница канала содержит возврат к будущим интеграциям', async ({ page }) => {
  await page.goto(`${fixture}?section=vk`);
  const back = page.getByRole('link', { name: 'Другие будущие интеграции' });
  await expect(back).toHaveAttribute('href', '/seller/integrations/planned');
  expect(await back.evaluate((element) => getComputedStyle(element).cursor)).toBe('pointer');
  await back.focus();
  await expect(back).toBeFocused();
});
