import {defineConfig} from '@playwright/test';
export default defineConfig({testDir:'.',testMatch:'preview-e507b.spec.ts',use:{browserName:'chromium'}});
