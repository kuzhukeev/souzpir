import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Start from '../../../resources/js/pages/Shell/Onboarding/Start';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';

const params = new URLSearchParams(location.search);
const theme = params.get('theme') ?? 'neutral';
document.documentElement.dataset.theme = theme;
const initial = { organization_name: 'Союз Пир', industry: 'Тест', region: 'Москва', timezone: 'Europe/Moscow', website: '', brand_name: 'Тестовый бренд', alias: '', theme, seller: true, vision: true, step: Number(params.get('step') ?? 1), completed: params.has('completed'), already_configured: false, logo_url: null };
const page = { component: 'Fixture', props: { initial, auth: { user: { id: 'u', name: 'Владелец', email: 'owner@example.test' } }, organization: null, brand: null, brands: [], modules: { seller: false, vision: false }, can: {}, unread: null, request_id: 'fixture', theme, translations: {}, ziggy: { routes: {} }, errors: {} }, url: '/start', version: null, clearHistory: false, encryptHistory: false };
const script = document.createElement('script'); script.type = 'application/json'; script.dataset.page = 'app'; script.textContent = JSON.stringify(page); document.body.appendChild(script);
void createInertiaApp({ resolve: () => Start, setup: ({ el, App, props }) => createRoot(el).render(<App {...props} />) });
