import {defineConfig} from '@playwright/test';
export default defineConfig({testDir:'.',testMatch:'compare.spec.ts',use:{channel:'chrome'}});
