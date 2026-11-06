import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';
import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Progress from '../../../resources/js/pages/Vision/Measurements/Progress';
const brandId = '01J00000000000000000000004';
const revision = 'a'.repeat(64);
const measurement = '01J00000000000000000000005';
const nativeFetch = window.fetch.bind(window);
window.fetch = (input, init) => {
  if (input === '/vision/runs/revision') return Promise.resolve(Response.json({ revision }));
  if (input === `/vision/runs/${measurement}/progress/data`) return Promise.resolve(Response.json({ revision, measurement: { status: 'running', created_at: '2026-11-06T12:00:00Z', started_at: new Date(Date.now() - 60000).toISOString(), finished_at: null, estimated_minutes: 20, pending_clarifications: null, answers: [{ question_n: 1, provider: 'gemini', requested_model: 'fake', status: 'ok' }, { question_n: 2, provider: 'gemini', requested_model: 'fake', status: 'failed' }, { question_n: 3, provider: 'gemini', requested_model: 'fake', status: 'uncertain' }, { question_n: 4, provider: 'gemini', requested_model: 'fake', status: 'pending' }] } }));
  return nativeFetch(input, init);
};
const props = { measurement, measurementBrandRevision: revision, auth: { user: { id: '1', name: 'Иван', email: 'a@example.test' } }, organization: { id: '1', name: 'Компания' }, brand: { id: brandId, name: 'Бренд', theme: 'neutral' }, brands: [{ id: brandId, name: 'Бренд' }], modules: { seller: true, vision: true }, can: { 'vision.measure.read': true }, unread: null, theme: new URLSearchParams(location.search).get('theme') ?? 'neutral', errors: {} };
const script = document.createElement('script'); script.type = 'application/json'; script.dataset.page = 'app'; script.textContent = JSON.stringify({ component: 'Fixture', props, url: '/vision/runs/test/progress', version: 'fixture', clearHistory: false, encryptHistory: false }); document.body.append(script);
void createInertiaApp({ resolve: () => Progress, setup: ({ el, App, props }) => createRoot(el).render(<App {...props} />) });
