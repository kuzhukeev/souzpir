import { defineConfig } from '@playwright/test';
export default defineConfig({testDir:'.',testMatch:/widget-(contact|session)\.spec\.ts/,workers:1,reporter:'list',use:{browserName:'chromium',launchOptions:process.platform === 'win32' ? {executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe'} : {}}});
