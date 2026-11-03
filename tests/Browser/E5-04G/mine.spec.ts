import { expect, test } from '@playwright/test';
test.use({ launchOptions: { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } });
test('карточка клиента показывает контакты и пропущенные поля', async ({page}) => {
  await page.setViewportSize({width:1440,height:900});
  await page.goto('http://127.0.0.1:5249/tests/Browser/E5-04G/fixture.html');
  const card = page.getByRole('complementary', {name:'Карточка клиента'});
  await expect(card.getByText('Телефон', {exact:true})).toBeVisible();
  await expect(card.getByText('anna@example.test')).toBeVisible();
  await expect(card.getByText('Источник', {exact:true})).toBeVisible();
  await expect(card.getByText('Телефон, Город')).toBeVisible();
  await page.screenshot({path:'.local/screenshots/sp-e5-04g/mine.png',fullPage:true});
});
