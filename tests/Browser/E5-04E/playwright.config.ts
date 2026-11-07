import { defineConfig } from '@playwright/test';
export default defineConfig({testDir:'.',testMatch:'filters.spec.ts',use:{channel:'chrome'}});
