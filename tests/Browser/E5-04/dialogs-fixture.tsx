import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';
import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Index from '../../../resources/js/pages/Seller/Dialogs/Index';

const selected = location.search.includes('selected');
const empty = location.search.includes('empty');
const brandId = '01J00000000000000000000004';
const id = '01J00000000000000000000005';
const dialogs = empty ? [] : [{ id, name: 'Мария Соколова', mode: 'bot', stage: 'qualify', outcome: null, channel: 'telegram', last_message: 'Уточните город доставки', updated_at: '2026-11-01T12:30:00Z', assigned_user_id: null }];
const dialog = !selected || empty ? null : { ...dialogs[0], summary: 'Клиент выбирает набор фейерверков для праздника.', email: 'maria@example.test', phone: '+7 900 000-00-00', slots: [
  ['name', 'Имя', 'Мария'], ['city', 'Город', 'Москва'], ['purpose', 'Цель', 'Праздник'], ['experience', 'Опыт в нише', null],
  ['outlet_type', 'Тип точки', null], ['outlet_floor', 'Этаж / помещение', null], ['categories', 'Интересующие категории', 'Фонтаны'],
  ['brands_interest', 'Интересующие бренды', null], ['current_supplier', 'Текущий поставщик', null], ['competitor_discount', 'Скидка у конкурента', null],
  ['budget_range', 'Бюджет', 'до 10 000 ₽'], ['timing', 'Сроки', 'ноябрь'], ['legal', 'Юридический статус', null], ['email', 'Почта', 'maria@example.test'],
  ['messenger', 'Мессенджер / телефон', 'Telegram'], ['next_contact_at', 'Когда связаться', null],
].map(([key, label, value]) => ({ key, label, value })), messages: [
  { id: '1', role: 'client', text: 'Добрый день! Нужен фейерверк для праздника.', created_at: '2026-11-01T12:29:00Z', meta: {} },
  { id: '2', role: 'agent', text: 'Уточните город доставки', created_at: '2026-11-01T12:30:00Z', meta: { intent: 'Доставка', goal: 'Уточнить город', guard: 'Пройден', tools: null, model: 'fake' } },
], tasks: [{ id: '3', type: 'followup', status: 'new', due_at: '2026-11-03T12:00:00Z' }] };
const props = {
  dialogs, dialog, filters: { mode: 'all', search: '' },
  auth: { user: { id: '1', name: 'Иван Петров', email: 'ivan@example.test' } }, organization: { id: '1', name: 'Союз Пир' },
  brand: { id: brandId, name: 'Пиротехника — Северные огни', theme: 'neutral' }, brands: [{ id: brandId, name: 'Пиротехника — Северные огни' }],
  modules: { seller: true, vision: true }, can: { 'seller.dialogs.read': true, 'seller.dialogues.operate': true },
  unread: null, request_id: 'fixture', theme: 'neutral', errors: {},
};
const script = document.createElement('script');
script.type = 'application/json'; script.dataset.page = 'app';
script.textContent = JSON.stringify({ component: 'Fixture', props, url: '/seller/dialogs', version: 'fixture', clearHistory: false, encryptHistory: false });
document.body.append(script);
void createInertiaApp({ resolve: () => Index, setup: ({ el, App, props }) => createRoot(el).render(<App {...props} />) });
