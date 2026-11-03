import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Wizard from '../../../resources/js/pages/Vision/Clarifications/Wizard';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';

const params = new URLSearchParams(location.search);
const step = params.get('step') ?? 'mentions';
const sample = { key: 'a'.repeat(64), value: step === 'mentions' ? 'Союз-Пир СПб' : step === 'presence' ? '2gis.ru' : 'Работает с 2009 года', excerpt: 'Нейросеть упомянула название и факт в ответе о бренде.', decision: null, correction: null, profile_url: null, add_alias: false };
const props = { measurement: '01J00000000000000000000001', step, available: !params.has('unavailable'), items: params.has('empty') || step === 'done' ? [] : [sample], total: 3, pending: 1, status: null,
  theme: 'neutral', brand: { id: 'brand', name: 'Пример бренда', theme: 'neutral' }, brands: [], organization: { id: 'org', name: 'Компания' }, auth: { user: { id: 'user', name: 'Иван Петров', email: 'ivan@example.test' } }, modules: { seller: false, vision: true }, can: {}, unread: null, translations: {}, ziggy: { routes: {} }, errors: {} };
const page = { component: 'Fixture', props, url: `/vision/runs/${props.measurement}/clarify/${step}`, version: null, clearHistory: false, encryptHistory: false };
const script = document.createElement('script'); script.type = 'application/json'; script.dataset.page = 'app'; script.textContent = JSON.stringify(page); document.body.appendChild(script);
void createInertiaApp({ resolve: () => Wizard, setup: ({ el, App, props: pageProps }) => createRoot(el).render(<App {...pageProps} />) });
