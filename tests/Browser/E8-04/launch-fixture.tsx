import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';
import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Index from '../../../resources/js/pages/Vision/Measurements/Index';

const brandId = '01J00000000000000000000004';
const revision = 'a'.repeat(64);
const originalFetch = window.fetch.bind(window);
window.fetch = (input, init) => {
  if (input === '/vision/runs/revision') {
    return Promise.resolve(new Response(JSON.stringify({ revision }), { status: 200, headers: { 'Content-Type': 'application/json' } }));
  }
  if (typeof input === 'string' && input.startsWith('/vision/runs/data')) {
    return Promise.resolve(new Response(JSON.stringify({
      revision, data: [{ created_at: '2026-10-20T12:30:00Z' }], page: 1, last_page: 1, total: 1,
    }), { status: 200, headers: { 'Content-Type': 'application/json' } }));
  }
  return originalFetch(input, init);
};
const props = {
  measurementBrandRevision: revision,
  status: null,
  auth: { user: { id: '1', name: 'Иван Петров', email: 'ivan@example.test' } },
  organization: { id: '1', name: 'Союз Пир' },
  brand: { id: brandId, name: 'Пиротехника — Северные огни', theme: 'neutral' },
  brands: [{ id: brandId, name: 'Пиротехника — Северные огни' }],
  modules: { seller: true, vision: true },
  can: { 'vision.measure.read': true, 'vision.measure.run': true },
  unread: null,
  request_id: 'fixture',
  theme: 'neutral',
  errors: {},
};
const script = document.createElement('script');
script.type = 'application/json';
script.dataset.page = 'app';
script.textContent = JSON.stringify({ component: 'Fixture', props, url: '/vision/runs', version: 'fixture', clearHistory: false, encryptHistory: false });
document.body.append(script);
void createInertiaApp({ resolve: () => Index, setup: ({ el, App, props }) => createRoot(el).render(<App {...props} />) });
