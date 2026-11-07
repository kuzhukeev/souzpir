import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';
import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Wizard from '../../../resources/js/pages/Vision/Clarifications/Wizard';
const brandId = '01J00000000000000000000004';
const measurement = '01J00000000000000000000005';
const props = { measurement, step: 'done', available: true, items: [], total: 3, pending: 0, status: null,
  recalculated: { before: 31, after: 34 }, alias_available: true,
  auth: { user: { id: '1', name: 'Иван', email: 'a@example.test' } }, organization: { id: '1', name: 'Компания' },
  brand: { id: brandId, name: 'Бренд', theme: 'neutral' }, brands: [{ id: brandId, name: 'Бренд' }],
  modules: { seller: true, vision: true }, can: { 'vision.measure.read': true }, unread: null, theme: 'neutral', errors: {} };
const script = document.createElement('script'); script.type = 'application/json'; script.dataset.page = 'app';
script.textContent = JSON.stringify({ component: 'Fixture', props, url: `/vision/runs/${measurement}/clarify/done`, version: 'fixture', clearHistory: false, encryptHistory: false });
document.body.append(script);
void createInertiaApp({ resolve: () => Wizard, setup: ({ el, App, props }) => createRoot(el).render(<App {...props} />) });
