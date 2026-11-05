import {defineConfig} from '@playwright/test';
export default defineConfig({testDir:'.',testMatch:'score.spec.ts',use:{channel:'chrome'}});
