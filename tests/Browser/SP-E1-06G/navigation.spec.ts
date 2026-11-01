import { expect, test, type Page } from '@playwright/test';

async function login(page: Page, email: string) {
  await page.goto('/login');
  await page.getByLabel('Рабочая почта').fill(email);
  await page.getByLabel('Пароль', { exact: true }).fill('test-only-password-123');
  await page.getByRole('button', { name: 'Войти', exact: true }).click();
  await expect(page).toHaveURL(/\/auth\/ready$/);
  await page.goto('/dashboard');
  await page.getByRole('button', { name: 'Продавец', exact: true }).click();
}

test('разрешённый сотрудник видит ссылку и открывает страницу', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await login(page, 'owner-e106g@example.test');
  const nav = page.getByRole('navigation', { name: 'Основная навигация' });
  const link = nav.getByRole('link', { name: 'Передача задач', exact: true });
  await expect(link).toBeVisible();
  await expect(link).toHaveAttribute('href', '/seller/tasks/transfer');
  await page.screenshot({ path: '.local/screenshots/sp-e1-06g/navigation-1440.png', fullPage: true });
  await link.click();
  await expect(page).toHaveURL(/\/seller\/tasks\/transfer$/);
  await expect(page.getByRole('heading', { name: 'Передача задач' })).toBeVisible();
});

test('сотруднику без права пункт скрыт, маршрут запрещён', async ({ page }) => {
  await login(page, 'viewer-e106g@example.test');
  const nav = page.getByRole('navigation', { name: 'Основная навигация' });
  await expect(nav.getByText('Передача задач', { exact: true })).toHaveCount(0);
  const response = await page.goto('/seller/tasks/transfer');
  expect(response?.status()).toBe(403);
});
