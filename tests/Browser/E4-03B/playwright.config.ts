import { defineConfig } from '@playwright/test';
export default defineConfig({ testDir: '.', testMatch: 'conditions.spec.ts', use: { baseURL: 'http://127.0.0.1:8255', channel: 'chrome' } });
