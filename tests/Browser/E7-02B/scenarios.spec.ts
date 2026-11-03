import { expect, test } from '@playwright/test';

const base = 'http://127.0.0.1:5238/tests/Browser/E7-02B/fixture.html';
test.use({ launchOptions: { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } });

test('list and editor match required controls', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto(base);
    await expect(page.getByRole('heading', { name: 'Контрольные сценарии' })).toBeVisible();
    await expect(page.getByRole('columnheader', { name: 'Ожидаемый итог' })).toBeVisible();
    await expect(page.getByRole('columnheader', { name: 'Последний результат' })).toBeVisible();
    await expect(page.getByRole('columnheader', { name: 'В наборе' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Копировать' })).toHaveCount(2);
    await expect(page.getByRole('button', { name: 'Запустить все' })).toBeDisabled();
    await page.screenshot({ path: '.local/screenshots/sp-e7-02b/list-1440.png', fullPage: true });
    await page.getByRole('button', { name: 'Изменить' }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog.getByRole('combobox', { name: 'Ожидаемый итог' })).toHaveValue('contact');
    await expect(dialog.getByRole('heading', { name: 'История прогонов' })).toBeVisible();
    await expect(dialog.getByRole('region', { name: 'История прогонов' }).getByText('Контакт получен')).toBeVisible();
    await page.screenshot({ path: '.local/screenshots/sp-e7-02b/editor-1440.png', fullPage: true });
    await dialog.getByRole('heading', { name: 'История прогонов' }).scrollIntoViewIfNeeded();
    await page.screenshot({ path: '.local/screenshots/sp-e7-02b/editor-history-1440.png', fullPage: true });
    await page.keyboard.press('Escape');
    await expect(dialog).toHaveCount(0);
});

test('empty list and empty editor history', async ({ page }) => {
    await page.goto(`${base}?empty`);
    await expect(page.getByText('Сценариев пока нет. Добавьте первый контрольный сценарий.')).toBeVisible();
    await page.getByRole('button', { name: /Добавить сценарий/ }).click();
    await expect(page.getByRole('dialog').getByText('Прогонов пока нет.')).toBeVisible();
});
