import { expect, test } from '@playwright/test';
import axe from 'axe-core';
const base = 'http://127.0.0.1:5248/tests/Browser/E8-02/pages-fixture.html';
test.use({
  launchOptions: { executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe' },
});
for (const screen of ['start', 'quiz', 'profile'])
  for (const theme of ['neutral', 'confectionery', 'pyro'])
    for (const width of [1440, 1024, 390])
      test(`${screen} ${theme} ${width}`, async ({ page }) => {
        await page.setViewportSize({ width, height: 900 });
        await page.route('**/*', (r) =>
          new URL(r.request().url()).origin === 'http://127.0.0.1:5248' ? r.continue() : r.abort(),
        );
        await page.goto(`${base}?screen=${screen}&theme=${theme}`);
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
        expect(
          await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
        ).toBeTruthy();
        await page.addScriptTag({ content: axe.source });
        const violations = await page.evaluate(
          async () =>
            (
              await (window as unknown as { axe: typeof axe }).axe.run(document, {
                runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa'] },
              })
            ).violations,
        );
        expect(violations).toEqual([]);
        await page.screenshot({
          path: `.local/screenshots/e8-02/${screen}-${theme}-${width}.png`,
          fullPage: true,
        });
      });

test('dirty quiz and profile guard sidebar navigation', async ({ page }) => {
  await page.goto(`${base}?screen=quiz`);
  await page.getByLabel('Название бренда', { exact: true }).fill('Несохранённый бренд');
  page.once('dialog', (dialog) => dialog.dismiss());
  await page.getByRole('link', { name: 'Главная', exact: true }).click();
  await expect(page.getByLabel('Название бренда', { exact: true })).toHaveValue(
    'Несохранённый бренд',
  );
  page.once('dialog', (dialog) => dialog.accept());
  await page.goto(base + '?screen=profile');
  await page.getByRole('button', { name: 'Изменить', exact: true }).first().click();
  await page.getByLabel('Название бренда', { exact: true }).fill('Несохранённый профиль');
  page.once('dialog', (dialog) => dialog.dismiss());
  await page.getByRole('link', { name: 'Главная', exact: true }).click();
  await expect(page.getByLabel('Название бренда', { exact: true })).toHaveValue(
    'Несохранённый профиль',
  );
});

test('failed confirmed navigation prompts again and retains input', async ({ page }) => {
  await page.route('**/dashboard', (r) => r.abort());
  await page.goto(`${base}?screen=quiz`);
  await page.getByLabel('Название бренда', { exact: true }).fill('Черновик');
  let dialogs = 0;
  page.on('dialog', async (dialog) => {
    dialogs++;
    if (dialogs === 1) await dialog.accept();
    else await dialog.dismiss();
  });
  await page.getByRole('link', { name: 'Главная', exact: true }).click();
  await expect.poll(() => dialogs).toBe(1);
  await page.waitForTimeout(500);
  await page.getByRole('link', { name: 'Главная', exact: true }).click();
  await expect.poll(() => dialogs).toBe(2);
  await expect(page.getByLabel('Название бренда', { exact: true })).toHaveValue('Черновик');
});
