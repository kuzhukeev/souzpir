import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';
import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Index from '../../../resources/js/pages/Shell/Integrations/Index';
import Api from '../../../resources/js/pages/Shell/Integrations/Api';
import Notifications from '../../../resources/js/pages/Shell/Integrations/Notifications';
const params = new URLSearchParams(location.search);
const theme =
  ['neutral', 'pyro', 'confectionery'].find((value) => value === params.get('theme')) ?? 'neutral';
const screen = params.get('screen') ?? 'index';
const component = screen === 'api' ? Api : screen === 'notifications' ? Notifications : Index;
const props = {
  auth: { user: { id: '1', name: 'Иван Петров', email: 'ivan@example.test' } },
  organization: { id: '1', name: 'Союз Пир' },
  brand: { id: '2', name: 'Пиротехника — Северные огни', theme },
  brands: [{ id: '2', name: 'Пиротехника — Северные огни' }],
  modules: { seller: true, vision: false },
  can: {
    'org.read': true,
    'team.read': true,
    'billing.read': true,
    'integrations.read': true,
    'profile.own': true,
  },
  unread: null,
  request_id: 'fixture',
  theme,
  translations: {},
  errors: {},
  ziggy: {
    routes: {
      company: { uri: 'company', methods: ['GET'] },
      brands: { uri: 'brands', methods: ['GET'] },
    },
    url: location.origin,
    port: null,
    defaults: {},
    location: { host: location.host, pathname: location.pathname, search: '' },
  },
  crmHref: '/seller/integrations/crm',
  connections: [
    {
      id: 'price_email',
      title: 'Почта для прайса',
      description: 'Отправка прайс-листа клиентам по почте.',
      status: 'configured',
      href: '/seller/channels/email',
      connected_at: null,
    },
    {
      id: 'telegram',
      title: 'Telegram-бот',
      description: 'Отвечает на сообщения клиентов в Telegram.',
      status: 'connected',
      href: '/seller/channels/telegram',
      connected_at: '2026-10-12T00:00:00Z',
    },
    {
      id: 'widget',
      title: 'Виджет сайта',
      description: 'Принимает обращения из браузера.',
      status: 'disconnected',
      href: '/seller/channels/widget',
      connected_at: null,
    },
    {
      id: 'whatsapp',
      title: 'WhatsApp',
      description: 'Сообщения клиентов в WhatsApp.',
      status: 'soon',
      href: null,
      connected_at: null,
    },
    {
      id: 'vk',
      title: 'VK',
      description: 'Обращения из ВКонтакте.',
      status: 'soon',
      href: null,
      connected_at: null,
    },
    {
      id: 'api',
      title: 'API-ключи и вебхуки',
      description: 'Доступ и события интеграций.',
      status: 'active',
      href: '/integrations/api',
      connected_at: null,
    },
    {
      id: 'notifications',
      title: 'Уведомления компании',
      description: 'Каналы для всей команды.',
      status: 'inactive',
      href: '/integrations/notifications',
      connected_at: null,
    },
    ...(params.has('channel-statuses')
      ? [
          {
            id: 'case-unconfigured',
            title: 'Канал без настройки',
            description: '',
            status: 'unconfigured',
            href: '/setup',
            connected_at: null,
          },
          {
            id: 'case-disabled',
            title: 'Выключенный канал',
            description: '',
            status: 'disabled',
            href: '/setup',
            connected_at: null,
          },
          {
            id: 'case-planned',
            title: 'Планируемый канал',
            description: '',
            status: 'planned',
            href: '/setup',
            connected_at: null,
          },
          {
            id: 'case-unknown',
            title: 'Неизвестный канал',
            description: '',
            status: 'future_internal_code',
            href: null,
            connected_at: null,
          },
        ]
      : []),
  ],
  keys: [
    {
      id: 'k1',
      name: 'CRM интеграция',
      mask: 'sk-••••a1b2',
      abilities: ['read', 'turn', 'leads'],
      created_at: '2026-10-12T00:00:00Z',
      last_used_at: null,
    },
  ],
  webhooks: [
    {
      id: 'w1',
      events: ['seller.lead.created'],
      secret: '••••••••',
      deliveries: [
        {
          id: 'd1',
          event: 'webhook.test',
          status: 'sent',
          status_code: 200,
          created_at: '2026-10-28T12:04:00Z',
        },
      ],
    },
  ],
  webhookEvents: [
    { value: 'seller.lead.created', label: 'Новый лид' },
    { value: 'seller.contact.captured', label: 'Контакт получен' },
  ],
  integration_api_key_available: params.has('secret'),
  integration_webhook_secret_available: params.has('secret'),
  permissions: { manage: true },
  apiBrandReadConfigured: params.has('brand-api-available'),
  apiKeyRuntimeAvailable: false,
  secureSecretTransport: !params.has('insecure'),
  secretStorageReady: !params.has('unsafe-storage'),
  webhookDeliveryAvailable: false,
  telegram_configured: false,
  telegram_mask: null,
  report_emails: [],
};
const script = document.createElement('script');
script.type = 'application/json';
script.dataset.page = 'app';
script.textContent = JSON.stringify({
  component: 'Fixture',
  props,
  url: location.pathname,
  version: 'fixture',
  clearHistory: false,
  encryptHistory: false,
});
document.body.append(script);
void createInertiaApp({
  resolve: () => component,
  setup: ({ el, App, props }) => createRoot(el).render(<App {...props} />),
});
