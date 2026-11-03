import { defineConfig } from '@playwright/test';
export default defineConfig({ testDir: '.', testMatch: 'review.spec.ts', use: { baseURL: 'http://127.0.0.1:8254', channel: 'chrome' } });

