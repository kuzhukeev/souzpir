import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import { useState } from 'react';
import { Criteria, Pages } from '../../../resources/js/features/Vision/Site/AuditDetails';
import { AuditProgress } from '../../../resources/js/pages/Vision/Site/Index';
import CabinetLayout from '../../../resources/js/layouts/CabinetLayout';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';

const json = '{"@type":"Product","name":"Проверенный товар"}';
const audit = { url: 'https://example.test', final_url: 'https://example.test', criteria: [
  { id: 4, title: 'Структурированные данные', group: 'Содержание страниц', status: 'partial', explanation: 'Найден JSON-LD.', evidence: [{ url: 'https://example.test/catalog', excerpt: json }] },
  { id: 99, title: 'Контакты', group: 'Содержание страниц', status: 'unknown', explanation: 'Данных недостаточно.', evidence: [] },
  { id: 6, title: 'Контактная страница', group: 'Содержание страниц', status: 'partial', explanation: 'Тип найден в разметке.', evidence: [{ url: 'https://example.test/contact', excerpt: '{"@type":"ContactPage"}' }] },
], pages: [{ url: 'https://example.test/catalog', primary_heading: 'Каталог', status: 200, observed_at: '2026-11-04T06:00:00Z' }, { url: 'https://example.test/contact', primary_heading: 'Связаться с нами', status: 200, observed_at: '2026-11-04T06:00:00Z' }] };
export function Fixture() {
  const [stage, setStage] = useState('inspecting');
  const events = [{ stage: 'queued', at: '2026-11-04T06:00:00Z' }, { stage: 'inspecting', at: '2026-11-04T06:01:00Z' }, ...(stage === 'complete' ? [{ stage: 'complete', at: '2026-11-04T06:02:00Z' }] : [])];
  return <CabinetLayout><main className="mx-auto max-w-[1100px] space-y-6 p-4"><h1 className="text-3xl font-bold">Аудит сайта</h1><AuditProgress progress={{ stage, events }} /><Criteria criteria={audit.criteria} canManage={false} /><Pages audit={audit} /><button type="button" onClick={() => setStage('complete')} className="cursor-pointer rounded-lg bg-(--color-action) p-3 text-white">Завершить проверку</button></main></CabinetLayout>;
}
const page = { component: 'Fixture', props: { theme: 'neutral', brand: { id: 'b', name: 'Пример бренда', theme: 'neutral' }, brands: [], organization: { id: 'o', name: 'Компания' }, auth: { user: { id: 'u', name: 'Иван', email: 'ivan@example.test' } }, modules: { seller: false, vision: true }, can: {}, unread: null, translations: {}, ziggy: { routes: {} }, errors: {} }, url: location.pathname, version: null, clearHistory: false, encryptHistory: false };
const script = document.createElement('script'); script.type = 'application/json'; script.dataset.page = 'app'; script.textContent = JSON.stringify(page); document.body.appendChild(script);
void createInertiaApp({ resolve: () => Fixture, setup: ({ el, App, props }) => createRoot(el).render(<App {...props} />) });
