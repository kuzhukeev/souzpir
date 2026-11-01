import { defineConfig } from '@playwright/test';
export default defineConfig({ testDir: '.', testMatch: 'readonly.spec.ts', use: { browserName: 'chromium', launchOptions: { executablePath: process.env.SP_UI_11_CHROME } } });
