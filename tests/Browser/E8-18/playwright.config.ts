import {defineConfig} from '@playwright/test';
export default defineConfig({testDir:'.',testMatch:'report.spec.ts',use:{channel:'chrome'}});
