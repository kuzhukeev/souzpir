import { defineConfig } from '@playwright/test';
export default defineConfig({testDir:'.',testMatch:'catalog.spec.ts',use:{channel:'chrome'}});
