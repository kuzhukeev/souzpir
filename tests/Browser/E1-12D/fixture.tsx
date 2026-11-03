import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Status from '../../../resources/js/pages/Shell/Support/Status';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';

const empty = location.search.includes('empty');
const observed = (id: string, name: string, state: string) => ({ id, name, status: empty ? 'unknown' : state, message: empty ? 'Мониторинг недоступен' : 'По сохранённым событиям', source: empty ? 'unavailable' : 'channel_event', checked_at: empty ? null : '2026-11-03T12:00:00Z' });
const page = { component: 'Fixture', props: {
  status: { checked_at: '2026-11-03T12:00:00Z', health_url: '/up', components: [
    { id: 'cabinet', name: 'Кабинет', status: 'operational', message: 'Проверен', source: 'app_response', checked_at: null },
    { id: 'database', name: 'База данных', status: 'operational', message: 'Проверена', source: 'database_probe', checked_at: null },
    observed('widget', 'Виджет', 'operational'), observed('telegram', 'Telegram', 'degraded'), observed('vision', 'Замеры', 'degraded'),
  ], providers: { available: !empty, items: [{ id: 'gemini', name: 'Gemini', status: empty ? 'unknown' : 'degraded', cooldown_until: null, checked_at: null, source: empty ? 'configuration' : 'ai_call' }], message: empty ? 'Нет данных' : 'Статус по вызовам ИИ' },
    incidents: { available: !empty, data: empty ? [] : [{ id: 'i', title: 'Ошибка замера Видения', occurred_at: '2026-11-03T12:00:00Z' }] } },
  theme: 'neutral', brand: { id: 'b', name: 'Пример бренда', theme: 'neutral' }, brands: [], organization: { id: 'o', name: 'Компания' },
  auth: { user: { id: 'u', name: 'Иван Петров', email: 'ivan@example.test' } }, modules: { seller: true, vision: true }, can: {}, translations: {}, ziggy: { routes: {} }, errors: {},
}, url: location.pathname + location.search, version: null, clearHistory: false, encryptHistory: false };
const script = document.createElement('script'); script.type = 'application/json'; script.dataset.page = 'app'; script.textContent = JSON.stringify(page); document.body.append(script);
void createInertiaApp({ resolve: () => Status, setup: ({ el, App, props }) => createRoot(el).render(<App {...props} />) });
