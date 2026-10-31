import { test, expect } from '@playwright/test';
import { createRequire } from 'node:module';
import { mkdir } from 'node:fs/promises';
import path from 'node:path';

const require = createRequire(import.meta.url);

for (const width of [390, 1024, 1440]) {
  test(`design system adapts at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 900 });
    const errors: string[] = [];
    page.on('pageerror', (error) => errors.push(error.message));
    const response = await page.goto('/dev/ds');
    expect(response?.status()).toBe(200);
    await expect(page.getByRole('heading', { name: 'Дизайн-система' })).toBeVisible();
    await page.evaluate(() => document.fonts.ready);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
      true,
    );
    const tiny = await page.evaluate(() =>
      Array.from(document.body.querySelectorAll('*'))
        .filter((el) =>
          Array.from(el.childNodes).some(
            (node) => node.nodeType === Node.TEXT_NODE && node.textContent?.trim(),
          ),
        )
        .filter((el) => {
          const rect = el.getBoundingClientRect();
          return rect.width > 1 && rect.height > 1 && getComputedStyle(el).visibility !== 'hidden';
        })
        .filter((el) => parseFloat(getComputedStyle(el).fontSize) < 13)
        .map((el) => el.textContent?.trim().slice(0, 40)),
    );
    expect(tiny).toEqual([]);
    await page.addScriptTag({ path: require.resolve('axe-core/axe.min.js') });
    const results = await page.evaluate(async () =>
      (
        window as unknown as {
          axe: { run: (root: Document, options: object) => Promise<{ violations: unknown[] }> };
        }
      ).axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21aa'] } }),
    );
    expect(results.violations).toEqual([]);
    const dir = path.join(process.cwd(), '.local', 'screenshots', 'design-system');
    await mkdir(dir, { recursive: true });
    await page.screenshot({
      path: path.join(dir, `components-${width}.png`),
      fullPage: true,
      animations: 'disabled',
    });
    expect(errors).toEqual([]);
  });
}

test('controls work with keyboard and dialogs restore focus', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/dev/ds');
  const tab = page.getByRole('tab', { name: 'Обзор' });
  await tab.focus();
  await page.keyboard.press('ArrowRight');
  await expect(page.getByRole('tab', { name: 'Детали' })).toHaveAttribute('aria-selected', 'true');
  await expect(page.getByRole('tabpanel')).toContainText('Содержимое деталей');
  const trigger = page.getByRole('button', { name: 'Открыть окно' });
  await trigger.click();
  await expect(page.getByRole('dialog', { name: 'Подтверждение' })).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(page.getByRole('dialog')).toHaveCount(0);
  await expect(trigger).toBeFocused();
  const wideTrigger = page.getByRole('button', { name: 'Открыть широкую панель' });
  await wideTrigger.click();
  const wideDrawer = page.getByRole('dialog', { name: 'Панель' });
  await expect(wideDrawer).toHaveCSS('width', '640px');
  await page.keyboard.press('Escape');
  await expect(wideTrigger).toBeFocused();
  const select = page.getByRole('combobox', { name: 'Статус' });
  await select.click();
  await page.getByRole('option', { name: 'Активен' }).click();
  await expect(select).toContainText('Активен');
  const city = page.getByRole('combobox', { name: 'Город' });
  await city.click();
  await page.getByRole('combobox', { name: 'Поиск: Город' }).fill('Каз');
  await page.getByRole('option', { name: 'Казань' }).click();
  await expect(city).toContainText('Казань');
  const theme = page.getByRole('combobox', { name: 'Тема' });
  await theme.click();
  await page.getByRole('option', { name: 'Пиротехника' }).click();
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'pyro');
});
