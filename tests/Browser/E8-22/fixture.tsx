import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import { Actions } from '../../../resources/js/features/Vision/Site/Actions';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';

const history = location.search.includes('history');
const actions = [{ id: 'a', title: 'Добавить раздел с условиями', description: 'Показать условия на сайте', status: history ? 'awaiting_measurement' : 'open', responsible: null, due_date: null, comment: null, completed_at: history ? '2026-11-01' : null, created_at: '2026-10-31' }];
const props = { actions, history, canManage: true, members: [], achievements: [], auth: { user: { id: 'u', name: 'Иван Петров', email: 'test@example.test' } }, brand: { id: 'b', name: 'Бренд А', theme: 'neutral' }, organization: { id: 'o', name: 'Компания' }, brands: [], modules: { vision: true, seller: false }, can: {}, unread: null, theme: 'neutral', translations: {}, ziggy: { routes: {}, url: location.origin, port: null, defaults: {}, location: { host: location.host, pathname: location.pathname, search: '' } }, errors: {} };
const script = document.createElement('script'); script.type = 'application/json'; script.dataset.page = 'app'; script.textContent = JSON.stringify({ component: 'Fixture', props, url: location.pathname, version: 'fixture', clearHistory: false, encryptHistory: false }); document.body.append(script);
void createInertiaApp({ resolve: () => Actions, setup: ({ el, App, props: appProps }) => createRoot(el).render(<App {...appProps} />) });
