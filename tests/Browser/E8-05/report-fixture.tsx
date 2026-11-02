import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';
import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Index from '../../../resources/js/pages/Vision/Reports/Index';

const brandId = '01J00000000000000000000004';
const revision = 'a'.repeat(64);
const empty = location.search.includes('empty');
const originalFetch = window.fetch.bind(window);
window.fetch = (input, init) => {
  if (input === '/vision/runs/revision') return Promise.resolve(new Response(JSON.stringify({ revision }), { status: 200, headers: { 'Content-Type': 'application/json' } }));
  if (typeof input === 'string' && input.startsWith('/vision/report/data')) return Promise.resolve(new Response(JSON.stringify({
    revision, confirmed: empty ? [] : [{ measurement_id: '01J00000000000000000000005', confirmed_at: '2026-10-20T12:30:00Z' }],
    reports: empty ? [] : [{ id: '01J00000000000000000000006', measurement_id: '01J00000000000000000000005', revision: 1, created_at: '2026-10-20T12:30:00Z' }],
    report: empty ? null : { id: '01J00000000000000000000006', measurement_id: '01J00000000000000000000005', visibility: 34, visibility_kind: 'available', coverage: 50, share_of_voice: null, position: 2.4, verified_errors: null, questions: 8, evidence_status: 'available', providers: [{ name: 'gemini', mention_rate: 34, coverage: 100 }, { name: 'chatgpt', mention_rate: null, coverage: 0 }] },
  }), { status: 200, headers: { 'Content-Type': 'application/json' } }));
  return originalFetch(input, init);
};
const props = {
  reportBrandRevision: revision, status: null,
  auth: { user: { id: '1', name: 'Иван Петров', email: 'ivan@example.test' } },
  organization: { id: '1', name: 'Союз Пир' },
  brand: { id: brandId, name: 'Пиротехника — Северные огни', theme: 'neutral' },
  brands: [{ id: brandId, name: 'Пиротехника — Северные огни' }],
  modules: { seller: true, vision: true }, can: { 'vision.measure.read': true, 'vision.measure.run': true, 'vision.report.read': true },
  unread: null, request_id: 'fixture', theme: 'neutral', errors: {},
};
const script = document.createElement('script');
script.type = 'application/json'; script.dataset.page = 'app';
script.textContent = JSON.stringify({ component: 'Fixture', props, url: '/vision/report', version: 'fixture', clearHistory: false, encryptHistory: false });
document.body.append(script);
void createInertiaApp({ resolve: () => Index, setup: ({ el, App, props }) => createRoot(el).render(<App {...props} />) });
