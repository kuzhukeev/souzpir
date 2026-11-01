import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: '.',
  testMatch: 'usage.spec.ts',
  use: {
    baseURL: 'http://127.0.0.1:5237',
    browserName: 'chromium',
    launchOptions: { executablePath: process.env.SP_UI_02_CHROME },
  },
});
