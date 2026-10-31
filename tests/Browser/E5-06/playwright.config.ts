import { defineConfig } from '@playwright/test';

process.env.PLAYWRIGHT_NO_COPY_PROMPT = '1';

export default defineConfig({
  testDir: '.',
  testMatch: '*.spec.ts',
  fullyParallel: false,
  workers: 1,
  retries: 0,
  timeout: 45_000,
  expect: { timeout: 12_000 },
  outputDir: '../../../.local/runtime/e5-06-playwright',
  reporter: [['list']],
  use: {
    baseURL: process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8256',
    channel: process.env.PLAYWRIGHT_CHANNEL || undefined,
    headless: true,
    screenshot: 'off',
    trace: 'off',
    video: 'off',
  },
});
