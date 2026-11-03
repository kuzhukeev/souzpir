import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Releases from '../../../resources/js/pages/Seller/Knowledge/Releases/Index';
import Limits from '../../../resources/js/pages/Seller/Settings/Limits/Index';
import Status from '../../../resources/js/pages/Shell/Support/Status';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';

const screen = new URLSearchParams(location.search).get('screen');
const page = {
  component: 'Fixture', props: {
    releases: [], draft: null, activeId: null, counts: {}, tasks: [], reviews: [], evaluations: [], canWrite: true, status: screen === 'status' ? {
      checked_at: '2026-11-01T12:00:00Z', health_url: '/up', components: [
        { id: 'cabinet', name: 'Кабинет', status: 'operational', message: 'Проверен', source: 'app_response', checked_at: null },
        { id: 'database', name: 'База данных', status: 'operational', message: 'Проверена', source: 'database_probe', checked_at: null },
        { id: 'widget', name: 'Виджет', status: 'unknown', message: 'Мониторинг недоступен', source: 'unavailable', checked_at: null },
      ], providers: { available: false, items: [], message: 'Нет данных' }, incidents: { available: false, data: [] },
    } : null,
    rows: [], budget: null, period: { from: '2026-11-01', to: '2026-11-30', timezone: 'UTC' },
    theme: 'neutral', brand: { id: 'b', name: 'Пример бренда', theme: 'neutral' }, brands: [],
    organization: { id: 'o', name: 'Компания' }, auth: { user: { id: 'u', name: 'Иван Петров', email: 'ivan@example.test' } },
    modules: { seller: true, vision: false }, can: {}, unread: null, translations: {}, ziggy: { routes: {} }, errors: {},
  }, url: location.pathname + location.search, version: null, clearHistory: false, encryptHistory: false,
};
const script = document.createElement('script');
script.type = 'application/json'; script.dataset.page = 'app'; script.textContent = JSON.stringify(page); document.body.appendChild(script);
void createInertiaApp({ resolve: () => screen === 'status' ? Status : screen === 'limits' ? Limits : Releases, setup: ({ el, App, props }) => createRoot(el).render(<App {...props} />) });
