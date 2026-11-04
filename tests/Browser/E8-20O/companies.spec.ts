import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';

let server: ViteDevServer;
let url: string;
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { server = await createServer({ configFile: 'tests/Browser/E8-20O/vite.config.ts' }); await server.listen(); url = 'http://127.0.0.1:5423/tests/Browser/E8-20O/fixture.html'; });
test.afterAll(async () => { await server?.close(); });

test('карточка, история и отслеживание компании', async ({ page }) => {
    await page.goto(url + '?edit=1');
    await expect(page.getByRole('button', { name: 'Отслеживать: Альфа ООО' })).toHaveText('☆');
    await mkdir('.local/screenshots/sp-e8-20o', { recursive: true });
    await page.screenshot({ path: '.local/screenshots/sp-e8-20o/companies.png', fullPage: true });
    const detail = page.locator('details').filter({ has: page.locator('summary').filter({ hasText: 'Альфа ООО' }) }).first();
    await detail.locator('summary').click();
    await expect(detail.getByText('В вопросах:')).toContainText('2 из 4');
    await expect(detail.getByRole('figure', { name: /3 сопоставимым замерам/ })).toBeVisible();
    await expect(detail.getByText('Где купить?', { exact: false })).toBeVisible();
    await expect(detail.getByText('Подтверждённые цитаты пока недоступны.')).toBeVisible();
    await page.screenshot({ path: '.local/screenshots/sp-e8-20o/company-card.png', fullPage: true });
});
