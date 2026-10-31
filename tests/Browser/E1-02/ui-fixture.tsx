import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Start from '../../../resources/js/pages/Shell/Onboarding/Start';
const params = new URLSearchParams(location.search);
const initial = { organization_name: 'Союз Пир', industry: 'Пиротехника', region: 'Москва', timezone: 'Europe/Moscow', website: '', brand_name: 'Союз Пир', alias: '', theme: 'pyro', seller: true, vision: true, step: Number(params.get('step') || 1), completed: params.has('completed'), already_configured: params.has('configured'), logo_url: null };
const shared = { initial, auth: { user: { id: 'test', name: 'Иван Петров', email: 'test@example.test' } }, organization: null, brand: null, brands: [], modules: { seller: params.has('enabled') ? true : null, vision: params.has('enabled') ? true : null }, can: {}, unread: null, request_id: 'fixture', theme: 'neutral', translations: {}, ziggy: { routes: params.has('routes') ? { vision: { uri: 'vision/quiz/1', methods: ['GET'] }, seller: { uri: 'seller/knowledge', methods: ['GET'] } } : {}, url: location.origin, port: null, defaults: {}, location: { host: location.host, pathname: location.pathname, search: '' } }, errors: {} };
const script = document.createElement('script'); script.type = 'application/json'; script.dataset.page = 'app'; script.textContent = JSON.stringify({ component: 'Fixture', props: shared, url: '/start', version: 'fixture', clearHistory: false, encryptHistory: false }); document.body.append(script);
async function mount() {
  const response = await fetch('/build/manifest.json');
  if (!response.ok) throw new Error('Build the app before the onboarding fixture');
  const manifest = await response.json() as Record<string, { file: string; css?: string[] }>;
  const styles = [manifest['resources/css/app.css'].file, ...(manifest['resources/js/app.tsx'].css ?? [])];
  await Promise.all([...new Set(styles)].map((file) => new Promise<void>((resolve, reject) => { const link = document.createElement('link'); link.rel = 'stylesheet'; link.href = '/build/' + file; link.onload = () => resolve(); link.onerror = () => reject(new Error('CSS missing')); document.head.append(link); })));
  await createInertiaApp({ resolve: () => Start, setup: ({ el, App, props }) => createRoot(el).render(<App {...props} />) });
}
void mount();
