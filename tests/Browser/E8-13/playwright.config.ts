import { defineConfig } from '@playwright/test';
export default defineConfig({testDir:'.',testMatch:'clarifications.spec.ts',use:{channel:'chrome'}});
