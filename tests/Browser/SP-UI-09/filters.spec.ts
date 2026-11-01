import { expect, test } from '@playwright/test';

const fixture = 'http://127.0.0.1:5237/tests/Browser/SP-UI-09/fixture.html';

test('сброс каждого фильтра возвращает все значения и первую страницу', async ({ page }) => {
  for (const filter of ['role', 'brand', 'status']) {
    for (const width of [390, 1440]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`${fixture}?filter=${filter}`);
      await expect(page.getByText(/Участников.*нет/).first()).toBeVisible();
      const reset = page.getByRole('button', { name: 'Сбросить фильтры' });
      await expect(reset).toBeVisible();
      let query: URLSearchParams | null = null;
      await page.route('**/team?*', async (route) => {
        query = new URL(route.request().url()).searchParams;
        const response = await page.evaluate(() => {
          const original = (window as unknown as { teamFixture: { props: Record<string, unknown> } }).teamFixture;
          return { ...original, url: '/team', props: { ...original.props, roleFilter: 'all', brandFilter: 'all', statusFilter: 'all', members: { data: [], total: 0, current_page: 1, last_page: 1 }, invitations: { data: [], total: 0, current_page: 1, last_page: 1, as_of: '2026-11-01T12:00:00Z' } } };
        });
        await route.fulfill({ status: 200, headers: { 'X-Inertia': 'true' }, json: response });
      });
      await reset.focus();
      await expect(reset).toBeFocused();
      if (filter === 'role' && width === 1440) await page.screenshot({ path: '.local/screenshots/sp-ui-09/filters-1440.png', fullPage: true });
      await page.keyboard.press('Enter');
      await expect(reset).toHaveCount(0);
      const params = query as URLSearchParams | null;
      expect(params?.get('role')).toBe('all');
      expect(params?.get('brand')).toBe('all');
      expect(params?.get('status')).toBe('all');
      expect(params?.get('page')).toBe('1');
      expect(params?.get('invitation_page')).toBe('1');
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
      await page.unroute('**/team?*');
    }
  }
});

test('без активных фильтров кнопка скрыта', async ({ page }) => {
  await page.goto(`${fixture}?filter=all`);
  await expect(page.getByRole('button', { name: 'Сбросить фильтры' })).toHaveCount(0);
});
