import { expect, test } from '@playwright/test';

const fixture = 'http://127.0.0.1:5237/tests/Browser/SP-UI-11/fixture.html';

test('завершённые настройки доступны для просмотра без указателя и сохранения', async ({ page }) => {
  for (const theme of ['neutral', 'pyro', 'confectionery']) {
    for (const width of [390, 1440]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`${fixture}?completed=1&theme=${theme}`);
      let writes = 0;
      page.on('request', (request) => { if (request.method() !== 'GET') writes++; });
      const back = page.getByRole('button', { name: 'Назад' });
      await back.focus();
      await page.keyboard.press('Enter');
      await expect(page.getByRole('heading', { name: 'Модули для настройки' })).toBeVisible();
      await expect(page.getByText('Настройка завершена. Данные доступны только для просмотра.')).toBeVisible();
      for (const box of await page.getByRole('checkbox').all()) {
        await expect(box).toBeDisabled();
        expect(await box.evaluate((input) => getComputedStyle(input).cursor)).toBe('default');
        expect(await box.locator('xpath=ancestor::label').evaluate((label) => getComputedStyle(label).cursor)).toBe('default');
      }
      await back.click();
      for (const radio of await page.getByRole('radio').all()) {
        await expect(radio).toBeDisabled();
        expect(await radio.locator('xpath=ancestor::label').evaluate((label) => getComputedStyle(label).cursor)).toBe('default');
      }
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
      if (theme === 'neutral' && width === 1440) await page.screenshot({ path: '.local/screenshots/sp-ui-11/readonly-1440.png', fullPage: true });
      await page.getByRole('button', { name: 'Далее' }).click();
      await expect(page.getByRole('heading', { name: 'Модули для настройки' })).toBeVisible();
      expect(writes).toBe(0);
    }
  }
});

test('до завершения карточки остаются доступными для выбора', async ({ page }) => {
  await page.goto(`${fixture}?step=2`);
  const theme = page.getByRole('radio', { name: /Кондитерская/ });
  await expect(theme).toBeEnabled();
  expect(await theme.locator('xpath=ancestor::label').evaluate((label) => getComputedStyle(label).cursor)).toBe('pointer');
  await theme.check();
  await expect(theme).toBeChecked();
  await page.goto(`${fixture}?step=3`);
  const module = page.getByRole('checkbox', { name: /Видение/ });
  await expect(module).toBeEnabled();
  expect(await module.evaluate((input) => getComputedStyle(input).cursor)).toBe('pointer');
  await module.focus();
  await page.keyboard.press('Space');
  await expect(module).not.toBeChecked();
});
