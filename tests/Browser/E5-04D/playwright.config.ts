import { defineConfig } from '@playwright/test';
export default defineConfig({ testDir: '.', testMatch: 'mine.spec.ts', use: { channel: 'chrome' } });
