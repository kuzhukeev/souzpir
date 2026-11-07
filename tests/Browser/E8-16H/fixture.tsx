import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';
import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Index from '../../../resources/js/pages/Vision/Measurements/Index';

const brandId = '01J00000000000000000000004';
const revision = 'a'.repeat(64);
const pending = window.location.search.includes('pending');
const insufficient = window.location.search.includes('insufficient');
const originalFetch = window.fetch.bind(window);
window.fetch = (input, init) => {
  if (input === '/vision/runs/revision') {
    return Promise.resolve(new Response(JSON.stringify({ revision }), { status: 200, headers: { 'Content-Type': 'application/json' } }));
  }
  if (typeof input === 'string' && input.startsWith('/vision/runs/data')) {
    return Promise.resolve(new Response(JSON.stringify({
      revision, plan: { questions: 2, models: 1 },
      slots: insufficient ? { used: 20, limit: 20, available: 0, reset_at: '2026-12-01T00:00:00Z' } : { used: 50, limit: 300, available: 250, reset_at: '2026-12-01T00:00:00Z' },
      data: [{ id: '01J00000000000000000000005', status: pending ? 'pending' : 'partial', launch_source: 'scheduled', pending_clarifications: 2, estimated_minutes: 4, visibility: 48, visibility_delta: -2, mentions: 3, created_at: '2026-10-20T12:30:00Z', answers: pending ? [
        { question_n: 1, provider: 'gemini', requested_model: 'google/gemini-2.5-flash', run_no: 1, status: 'pending', answer_text: null, error_code: null },
      ] : [
        { question_n: 1, provider: 'gemini', requested_model: 'google/gemini-2.5-flash', run_no: 1, status: 'ok', answer_text: 'Сохранённый ответ бренда', error_code: null },
        { question_n: 2, provider: 'gemini', requested_model: 'google/gemini-2.5-flash', run_no: 1, status: 'failed', answer_text: null, error_code: 'provider_rejected' },
      ] }], page: 1, last_page: 1, total: 1,
      schedule: { enabled: true, frequency: 'weekly', weekday: 1, local_time: '09:00', timezone: 'Europe/Moscow',
        next_run_at: '2026-11-09T06:00:00Z', models: ['google/gemini-2.5-flash'], available_models: ['google/gemini-2.5-flash'] },
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
