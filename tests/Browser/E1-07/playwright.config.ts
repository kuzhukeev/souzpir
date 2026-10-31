import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: '.',
  testMatch: '**/*.spec.ts',
  fullyParallel: false,
  workers: 1,
  retries: 0,
  timeout: 60_000,
  expect: { timeout: 12_000 },
  outputDir: '../../../.local/runtime/e1-07-playwright',
  reporter: [['list']],
  use: {
    baseURL: 'https://127.0.0.1:8107',
    ignoreHTTPSErrors: true,
    channel: 'chrome',
    headless: true,
    launchOptions: {
      args: [
        '--disable-background-networking',
        '--disable-component-update',
        '--disable-domain-reliability',
        '--disable-sync',
        '--no-first-run',
        '--host-resolver-rules=MAP * ~NOTFOUND, EXCLUDE 127.0.0.1',
      ],
    },
    trace: 'off',
    video: 'off',
  },
});
