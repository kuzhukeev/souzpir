import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Company from '../../../resources/js/pages/Shell/Organizations/Company';
import Brands from '../../../resources/js/pages/Shell/Organizations/Brands';
import Modules from '../../../resources/js/pages/Shell/Organizations/Modules';
const params = new URLSearchParams(location.search);
const screen = params.get('screen') ?? 'company';
const brandId = '01J00000000000000000000004';
const company = { id: '01J00000000000000000000001', name: 'Союз Пир', legal_name: 'ООО Союз Пир', inn: '7701234567', industry: 'Пиротехника', country: 'Россия', region: 'Москва', timezone: 'Europe/Moscow' };
const owner = { id: '01J00000000000000000000002', name: 'Иван Петров', email: 'ivan@example.test' };
const target = { id: '01J00000000000000000000003', name: 'Анна Смирнова', email: 'anna@example.test' };
const items = [{ id: brandId, name: 'Пиротехника', website: 'https://example.test', theme: 'pyro', status: 'active', logo_url: null, member_count: 3, modules: { seller: true, vision: false } }, { id: '01J00000000000000000000005', name: 'Северные огни', website: null, theme: 'neutral', status: 'archived', logo_url: null, member_count: 1, modules: { seller: false, vision: false } }];
const limit = { value: null, unit: '', configured: false };
const usage = { value: null, unit: '', status: 'unavailable' };
const pageData = screen === 'brands' ? { items: params.has('empty') ? [] : items, activeCount: 1, brandLimit: limit, permissions: { manage: true } } : screen === 'modules' ? { targetBrand: { id: brandId, name: 'Пиротехника', status: 'active' }, items: { seller: { enabled: true, configured_enabled: true, allowed: true, limit, quota: limit, usage }, vision: { enabled: false, configured_enabled: false, allowed: true, limit, quota: limit, usage } } } : { company, owner, candidates: [target], permissions: { manage: true, transfer: true } };
const shared = { ...pageData, auth: { user: owner }, organization: { id: company.id, name: company.name }, brand: { id: brandId, name: 'Пиротехника', theme: 'pyro' }, brands: [{ id: brandId, name: 'Пиротехника' }], modules: { seller: true, vision: false }, can: { 'org.read': true, 'team.read': true, 'billing.read': true, 'integrations.read': true, 'profile.own': true, 'seller.dialogs.read': true, 'seller.customers.read': true, 'seller.knowledge.read': true, 'seller.settings.read': true, 'vision.report.read': true, 'vision.measure.read': true }, unread: null, request_id: 'fixture', theme: 'pyro', translations: {}, ziggy: { routes: { company: { uri: 'company', methods: ['GET'] }, brands: { uri: 'brands', methods: ['GET'] } }, url: location.origin, port: null, defaults: {}, location: { host: location.host, pathname: location.pathname, search: '' } }, errors: {} };
const script = document.createElement('script'); script.type = 'application/json'; script.dataset.page = 'app'; script.textContent = JSON.stringify({ component: 'Fixture', props: shared, url: screen === 'brands' ? '/brands' : screen === 'modules' ? `/brands/${brandId}/modules` : '/company', version: 'fixture', clearHistory: false, encryptHistory: false }); document.body.append(script);
async function mount() {
  const response = await fetch('/build/manifest.json'); if (!response.ok) throw new Error('Build the app before the fixture');
  const manifest = await response.json() as Record<string, { file: string; css?: string[] }>;
  const styles = [manifest['resources/css/app.css'].file, ...(manifest['resources/js/app.tsx'].css ?? [])];
  await Promise.all([...new Set(styles)].map((file) => new Promise<void>((resolve, reject) => { const link = document.createElement('link'); link.rel = 'stylesheet'; link.href = '/build/' + file; link.onload = () => resolve(); link.onerror = () => reject(new Error('CSS missing')); document.head.append(link); })));
  await createInertiaApp({ resolve: () => screen === 'brands' ? Brands : screen === 'modules' ? Modules : Company, setup: ({ el, App, props }) => createRoot(el).render(<App {...props} />) });
}
void mount();
