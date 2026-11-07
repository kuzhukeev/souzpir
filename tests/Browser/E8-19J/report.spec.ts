import { expect, test } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
let full: string; let empty: string; let expired: string;
test.setTimeout(60000);
test.use({ viewport: { width: 1440, height: 900 }, colorScheme: 'light', launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(() => {
 mkdirSync('.local/tmp', { recursive: true });
 const render = (mode: string) => execFileSync(process.env.PHP_BINARY || 'php', ['-d', `sys_temp_dir=${resolve('.local/tmp')}`, 'tests/Browser/E8-19J/render.php', mode], { encoding: 'utf8', env: { ...process.env, APP_ENV: 'local', APP_INSTANCE: 'e819j-fixture' } });
 full = render('full'); empty = render('empty'); expired = render('expired');
});
test('четыре блока показывают только опубликованные показатели на разных ширинах', async ({ page }) => {
 for (const width of [1440, 1024, 390]) {
  await page.setViewportSize({ width, height: 900 }); await page.setContent(full);
  const cards = page.locator('.insights-grid > section'); await expect(cards).toHaveCount(4);
  await expect(cards.nth(0)).toContainText('Видимость бренда: 64,0%');
  await expect(cards.nth(1)).toContainText('Компания Б');
  await expect(cards.nth(1).locator('.bar span').nth(1)).toHaveAttribute('style', 'width:50%');
  await expect(cards.nth(2)).toContainText('Верно'); await expect(cards.nth(2)).toContainText('18');
  await expect(cards.nth(2)).toContainText('Требует уточнения'); await expect(cards.nth(2)).toContainText('3');
  await expect(cards.nth(3)).toContainText('72'); await expect(cards.nth(3)).toContainText('Доступность сайта');
  await expect(page.getByText('PRIVATE_ANSWER_DO_NOT_PUBLISH')).toHaveCount(0);
  const first = await cards.nth(0).boundingBox(); const second = await cards.nth(1).boundingBox();
  if (width > 760) expect(Math.abs(first!.y - second!.y)).toBeLessThan(2); else expect(second!.y).toBeGreaterThan(first!.y);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
  if (width === 1440) { mkdirSync('.local/screenshots/SP-E8-19J', { recursive: true }); await page.screenshot({ path: '.local/screenshots/SP-E8-19J/report.png', fullPage: true }); }
 }
});
test('отсутствующие показатели и недоступная ссылка не раскрывают данные', async ({ page }) => {
 await page.emulateMedia({ colorScheme: 'dark' });
 await page.setContent(empty);
 await page.keyboard.press('Tab');
 await expect(page.getByText('Вопросы', { exact: true })).toBeFocused(); await expect(page.getByText('Проверенных выводов для этого снимка пока нет.')).toBeVisible();
 await expect(page.getByText('Нет подтверждённых упоминаний компаний.')).toBeVisible();
 await expect(page.getByText('Счётчики проверенных утверждений в этой публикации недоступны.')).toBeVisible();
 await expect(page.getByText('Оценка сайта в этой публикации недоступна.')).toBeVisible();
 await page.setContent(expired); await expect(page.getByRole('heading', { name: 'Ссылка недоступна' })).toBeVisible();
 await expect(page.getByText('Пример бренда')).toHaveCount(0); await expect(page.locator('.insights-grid')).toHaveCount(0);
});
