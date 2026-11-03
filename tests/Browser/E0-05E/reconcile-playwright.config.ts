import { defineConfig } from '@playwright/test';
export default defineConfig({testDir:'.',testMatch:'reconcile.spec.ts',use:{channel:'chrome'}});
