import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';
import { mkdir } from 'node:fs/promises';
let server: ViteDevServer; let url: string;
test.use({ viewport: { width: 1440, height: 900 }, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });
test.beforeAll(async () => { server = await createServer({ configFile: 'tests/Browser/E4-10J/vite.config.ts', server: { host: '127.0.0.1', port: 0, strictPort: false } }); await server.listen(); url = new URL('/tests/Browser/E4-10J/fixture.html', server.resolvedUrls!.local[0]).href; });
test.afterAll(async () => { await server?.close(); });
test('выбор и страницы сохраняют фильтры и показ связей без выдуманных данных', async ({ page }) => {
 await page.goto(url + '?many');
 const list = page.getByRole('heading', {name:'Список фактов'}).locator('..');
 await expect(list.getByText('1–10 из 12')).toBeVisible();
 await list.getByLabel('Выбрать факты на странице').check();
 await expect(list.getByText('Выбрано: 10')).toBeVisible();
 await list.getByRole('button', {name:'Вперёд'}).click();
 await expect(list.getByText('11–12 из 12')).toBeVisible();
 await expect(list.getByTitle('Связь с условиями не указана').first()).toHaveText('—');
 await list.getByRole('searchbox').fill('Доставка 12');
 await expect(list.getByText('1–1 из 1')).toBeVisible();
 await mkdir('.local/screenshots/SP-E4-10J', { recursive:true });
 await page.screenshot({path:'.local/screenshots/SP-E4-10J/facts.png',fullPage:true});
});
test('пустой список и отсутствие результатов различаются', async ({ page }) => {
 await page.goto(url + '?empty');
 await expect(page.getByText('Фактов пока нет.')).toBeVisible();
 await page.goto(url);
 await page.getByRole('searchbox').last().fill('ничего не найдено');
 await expect(page.getByText('По запросу факты не найдены.')).toBeVisible();
});
