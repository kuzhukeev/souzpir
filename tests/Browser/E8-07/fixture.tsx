import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import '../../../resources/css/app.css';
import.meta.glob('../../../resources/css/themes/**/*.css', { eager: true });
import Trends from '../../../resources/js/pages/Vision/Settings/Trends';
const rows = [
  { id: 'one', date: '2026-10-01T12:00:00Z', visibility: 34, share: 27, providers: { Gemini: { visibility: 34, position: 3 } }, intents: { choose: 34 }, entities: { 'Компания А': 27 } },
  { id: 'two', date: '2026-10-15T12:00:00Z', visibility: 48, share: 36, providers: { Gemini: { visibility: 48, position: 2 } }, intents: { choose: 48 }, entities: { 'Компания А': 36 } },
];
const props = { rows, error: false, auth: { user: { id: 'test', name: 'Иван Петров', email: 'test@example.test' } }, organization: null, brand: null, brands: [], modules: { seller: true, vision: true }, can: {}, unread: null, request_id: 'fixture', theme: 'neutral', translations: {}, ziggy: { routes: {}, url: location.origin, port: null, defaults: {}, location: { host: location.host, pathname: location.pathname, search: '' } }, errors: {} };
const script = document.createElement('script'); script.type = 'application/json'; script.dataset.page = 'app'; script.textContent = JSON.stringify({ component: 'Fixture', props, url: '/vision/trends', version: 'fixture', clearHistory: false, encryptHistory: false }); document.body.append(script);
void createInertiaApp({ resolve: () => Trends, setup: ({ el, App, props }) => createRoot(el).render(<App {...props} />) });

