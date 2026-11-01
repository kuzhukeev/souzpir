import { defineConfig } from '@playwright/test';
export default defineConfig({ testDir: '.', testMatch: 'filters.spec.ts', use: { browserName: 'chromium', launchOptions: { executablePath: process.env.SP_UI_09_CHROME } } });
