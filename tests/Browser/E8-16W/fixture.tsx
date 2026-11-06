import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';
import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Progress from '../../../resources/js/pages/Vision/Measurements/Progress';
const brandId = '01J00000000000000000000004';
const revision = 'a'.repeat(64);
const measurement = '01J00000000000000000000005';
const nativeFetch = window.fetch.bind(window);
const params = new URLSearchParams(location.search);
let requests = 0;
window.fetch = (input, init) => {
  if (input === '/vision/runs/revision') return Promise.resolve(Response.json({ revision }));
  if (input === `/vision/runs/${measurement}/progress/data`) {
    requests++;
    if (params.has('fail') && requests === 1) return Promise.reject(new Error('offline'));
    const complete = params.has('complete');
    const payload = { revision, measurement: { status: complete ? 'completed' : 'running', created_at: '2026-11-06T12:00:00Z', started_at: complete ? '2026-11-06T12:10:00Z' : new Date(Date.now()-60000).toISOString(), finished_at: complete ? '2026-11-06T12:30:00Z' : null, estimated_minutes: 20, pending_clarifications: params.has('unknown') ? null : complete ? Number(params.get('count') ?? '2') : null, response_events: params.has('events') ? [{kind:'first_answer',at:'2026-11-06T12:11:00Z'},{kind:'first_error',at:'2026-11-06T12:12:00Z'}] : [], answers: params.has('empty') ? [] : [{question_n:1,provider:'gemini',requested_model:'fake',status:'ok'},{question_n:2,provider:'gemini',requested_model:'fake',status:complete?'ok':'pending'}] } };
    if (params.has('delay') && requests === 1) return new Promise(resolve=>setTimeout(()=>resolve(Response.json(payload)),1500));
    return Promise.resolve(Response.json(payload));
  }
  return nativeFetch(input, init);
};
const props = { measurement, measurementBrandRevision: revision, auth: { user: { id: '1', name: 'Иван', email: 'a@example.test' } }, organization: { id: '1', name: 'Компания' }, brand: { id: brandId, name: 'Бренд', theme: 'neutral' }, brands: [{ id: brandId, name: 'Бренд' }], modules: { seller: true, vision: true }, can: { 'vision.measure.read': true }, unread: null, theme: new URLSearchParams(location.search).get('theme') ?? 'neutral', errors: {} };
const script = document.createElement('script'); script.type = 'application/json'; script.dataset.page = 'app'; script.textContent = JSON.stringify({ component: 'Fixture', props, url: '/vision/runs/test/progress', version: 'fixture', clearHistory: false, encryptHistory: false }); document.body.append(script);
void createInertiaApp({ resolve: () => Progress, setup: ({ el, App, props }) => createRoot(el).render(<App {...props} />) });
