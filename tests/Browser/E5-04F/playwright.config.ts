import { defineConfig } from '@playwright/test';
export default defineConfig({testDir:'.',testMatch:'list.spec.ts',use:{channel:'chrome'}});
