import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';
import type { BrandDomain } from '../../../resources/js/types/Shell/Brands';
import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Settings from '../../../resources/js/pages/Shell/Brands/Settings';
const params = new URLSearchParams(location.search);
const theme =
  ['neutral', 'pyro', 'confectionery'].find((value) => value === params.get('theme')) ?? 'neutral';
const brandId = '01J00000000000000000000004';
const owner = { id: '01J00000000000000000000002', name: 'Иван Петров', email: 'ivan@example.test' };
const limit = { value: null, unit: '', configured: false };
const targetBrand = {
  id: brandId,
  name: 'Пиротехника — Северные огни',
  website: 'https://example.test',
  industry: 'Пиротехника',
  market: 'Россия, Москва',
  language: 'ru',
  timezone: 'Europe/Moscow',
  theme,
  status: 'active',
  logo_url: '/private-logo.svg',
  website_check: null,
  settings: {
    aliases: ['Северные огни', 'Northern Lights'],
    theme: { subtitle: 'Праздник начинается здесь', promo_card: true },
  },
};
const domains: BrandDomain[] = params.has('empty')
  ? []
  : [
      {
        id: '01J00000000000000000000011',
        host: 'example.test',
        purpose: 'widget_and_visibility',
        verification_method: 'file',
        status: 'pending',
        verified_at: null,
        verification: {
          file_path: '/.well-known/eyon-domain-verification.txt',
          file_content: 'fixture-proof-for-example-domain',
          dns_name: '_eyon.example.test',
          dns_value: 'fixture-proof-for-example-domain',
        },
      },
      {
        id: '01J00000000000000000000012',
        host: 'shop.example.test',
        purpose: 'widget',
        verification_method: 'dns',
        status: 'verified',
        verified_at: '2026-10-31T10:00:00Z',
        verification: {
          file_path: '/.well-known/eyon-domain-verification.txt',
          file_content: 'fixture-proof-for-shop-domain',
          dns_name: '_eyon.shop.example.test',
          dns_value: 'fixture-proof-for-shop-domain',
        },
      },
    ];
if (!params.has('empty'))
  domains.push({
    id: '01J00000000000000000000013',
    host: 'unverified.example.test',
    purpose: 'widget',
    verification_method: 'file',
    status: 'failed',
    verified_at: null,
    last_error: 'Проверочный файл не найден. Проверьте путь и содержимое файла.',
    last_checked_at: '2026-10-31T11:00:00Z',
    verification: {
      file_path: '/.well-known/eyon-domain-verification.txt',
      file_content: 'fixture-failed-proof',
      dns_name: '_eyon.unverified.example.test',
      dns_value: 'fixture-failed-proof',
    },
  });
const moduleItems = {
  seller: {
    enabled: true,
    configured_enabled: true,
    allowed: true,
    limit,
    quota: limit,
    usage: { value: null, unit: '', status: 'unavailable' },
  },
  vision: {
    enabled: false,
    configured_enabled: false,
    allowed: true,
    limit,
    quota: limit,
    usage: { value: null, unit: '', status: 'unavailable' },
  },
};
const shared = {
  targetBrand,
  domains,
  moduleItems,
  networkChecksEnabled: params.get('checks') === '1',
  permissions: { manage: true },
  auth: { user: owner },
  organization: { id: '01J00000000000000000000001', name: 'Союз Пир' },
  brand: { id: brandId, name: targetBrand.name, theme },
  brands: [{ id: brandId, name: targetBrand.name }],
  modules: { seller: true, vision: false },
  can: {
    'org.read': true,
    'team.read': true,
    'billing.read': true,
    'integrations.read': true,
    'profile.own': true,
    'seller.dialogs.read': true,
    'seller.customers.read': true,
    'seller.knowledge.read': true,
    'seller.settings.read': true,
    'vision.report.read': true,
    'vision.measure.read': true,
  },
  unread: null,
  request_id: 'fixture',
  theme,
  translations: {},
  ziggy: {
    routes: {
      company: { uri: 'company', methods: ['GET'] },
      brands: { uri: 'brands', methods: ['GET'] },
    },
    url: location.origin,
    port: null,
    defaults: {},
    location: { host: location.host, pathname: location.pathname, search: '' },
  },
  errors: {},
};
const script = document.createElement('script');
script.type = 'application/json';
script.dataset.page = 'app';
script.textContent = JSON.stringify({
  component: 'Shell/Brands/Settings',
  props: shared,
  url: '/brands/' + brandId + '/settings',
  version: 'fixture',
  clearHistory: false,
  encryptHistory: false,
});
document.body.append(script);
void createInertiaApp({resolve:()=>Settings,setup:({el,App,props})=>createRoot(el).render(<App {...props}/>)});
