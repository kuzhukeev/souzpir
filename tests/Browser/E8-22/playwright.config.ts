import { defineConfig } from '@playwright/test';
export default defineConfig({ testDir: '.', testMatch: 'actions.spec.ts', use: { channel: 'chrome' } });
