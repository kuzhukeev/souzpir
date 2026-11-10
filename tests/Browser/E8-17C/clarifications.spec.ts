import { expect, test } from '@playwright/test';
import { createServer, type ViteDevServer } from 'vite';

let server: ViteDevServer;
let fixture: string;
test.setTimeout(60000);
test.use({viewport: {width: 1440, height: 900}, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? {executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH} : {}});
test.beforeAll(async () => {
  server = await createServer({configFile: 'tests/Browser/E8-17C/vite.config.ts', server: {host: '127.0.0.1', port: 0, strictPort: false}});
  await server.listen();
  fixture = new URL('/tests/Browser/E8-17C/fixture.html', server.resolvedUrls!.local[0]).href;
});
test.afterAll(async () => { await server?.close(); });

test('площадка из анкеты показана в уточнениях и доступна из общего меню', async ({page}) => {
  await page.goto(`${fixture}?page=presence`);
  await expect(page.getByText('Из анкеты')).toBeVisible();
  await expect(page.getByRole('link', {name: 'Открыть профиль'})).toHaveAttribute('href', 'https://2gis.ru/org/123');
  const menu = page.getByRole('navigation', {name: 'Основная навигация'});
  await expect(menu.getByRole('link', {name: 'Уточнения'})).toBeVisible();
  await page.screenshot({path: '.local/screenshots/SP-E8-17C/presence.png'});
});

test('завершение поясняет пересчёт, а отчёт показывает исходную и уточнённую видимость', async ({page}) => {
  await page.goto(`${fixture}?page=done`);
  await expect(page.getByRole('heading', {name: 'Спасибо. Отчёт пересчитан с учётом ваших уточнений'})).toBeVisible();
  await expect(page.getByText(/Видимость: 31 % → 34 %/)).toBeVisible();
  await page.screenshot({path: '.local/screenshots/SP-E8-17C/done.png'});
  await page.goto(`${fixture}?page=report`);
  await expect(page.getByText(/31 % → 34 %/)).toBeVisible();
  await page.screenshot({path: '.local/screenshots/SP-E8-17C/report.png'});
});
