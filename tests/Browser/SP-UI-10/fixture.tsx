import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Index from '../../../resources/js/pages/Shell/Data/Index';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';

const translations = {
  'Shell/Data/messages.page_title': 'Данные и согласия',
  'Shell/Data/messages.page_subtitle': 'Согласия, экспорт и хранение данных',
  'Shell/Data/messages.consents': 'Согласия',
  'Shell/Data/messages.terms': 'Условия использования',
  'Shell/Data/messages.terms_description': 'Правила использования платформы',
  'Shell/Data/messages.not_accepted': 'Не приняты',
  'Shell/Data/messages.ai': 'Обработка внешними ИИ-провайдерами',
  'Shell/Data/messages.ai_description': 'Передача данных для работы с ИИ-моделями',
  'Shell/Data/messages.ai_not_accepted': 'Согласие не дано',
  'Shell/Data/messages.news': 'Получать новости',
  'Shell/Data/messages.news_off': 'Не получать новости',
  'Shell/Data/messages.news_description': 'Информационные письма',
  'Shell/Data/messages.export_title': 'Экспорт данных',
  'Shell/Data/messages.export_description': 'Выберите раздел и период.',
  'Shell/Data/messages.export_limit': 'Доступен экспорт согласий.',
  'Shell/Data/messages.module': 'Модуль',
  'Shell/Data/messages.from': 'С даты',
  'Shell/Data/messages.to': 'По дату',
  'Shell/Data/messages.prepare': 'Подготовить архив',
  'Shell/Data/messages.empty': 'Архивов пока нет.',
  'Shell/Data/messages.archive_ready': 'Архив подготовлен.',
};
const page = {
  component: 'Fixture',
  props: {
    consents: { terms_accepted_at: null, ai_consent_at: null, marketing_opt_in: false, marketing_changed_at: null },
    exports: [],
    modules: [{ value: 'cabinet', label: 'Кабинет' }],
    permissions: { can_export: true },
    theme: 'neutral',
    brand: { id: 'b', name: 'Пример бренда', theme: 'neutral' },
    brands: [],
    organization: { id: 'o', name: 'Компания' },
    auth: { user: { id: 'u', name: 'Иван Петров', email: 'ivan@example.test' } },
    can: {},
    unread: null,
    translations,
    ziggy: { routes: {} },
    errors: {},
  },
  url: '/settings/data',
  version: null,
  clearHistory: false,
  encryptHistory: false,
};
const script = document.createElement('script');
script.type = 'application/json';
script.dataset.page = 'app';
script.textContent = JSON.stringify(page);
document.body.appendChild(script);
void createInertiaApp({
  resolve: () => Index,
  setup: ({ el, App, props }) => createRoot(el).render(<App {...props} />),
});
