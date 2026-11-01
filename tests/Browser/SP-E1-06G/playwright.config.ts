import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: '.', testMatch: 'navigation.spec.ts',
  use: { baseURL: 'http://127.0.0.1:8190', browserName: 'chromium', launchOptions: { executablePath: process.env.SP_E106G_CHROME } },
});
