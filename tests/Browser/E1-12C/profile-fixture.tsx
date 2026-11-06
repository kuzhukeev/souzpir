import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';
import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Profile from '../../../resources/js/pages/Shell/Profile/Profile';
import Security from '../../../resources/js/pages/Shell/Profile/Security';
import Notifications from '../../../resources/js/pages/Shell/Profile/Notifications';
import Ready from '../../../resources/js/pages/Shell/Auth/Ready';
import { BrandSwitch } from '../../../resources/js/components/navigation/BrandSwitch';
import { profileCopy } from '../../../resources/js/features/Shell/Profile/copy';

const params = new URLSearchParams(location.search);
const screen = params.get('screen') ?? 'profile';
export function SwitchFixture() {
  return <BrandSwitch ready brand={{ id: '01J00000000000000000000004', name: 'Первый бренд', theme: 'neutral' }} brands={[{ id: '01J00000000000000000000004', name: 'Первый бренд' }, { id: '01J00000000000000000000005', name: 'Второй бренд' }]} />;
}
const theme = params.get('theme') ?? 'neutral';
const owner = { id: '01J00000000000000000000002', name: 'Иван Петров', email: 'ivan@example.test' };
const titles = {
  seller: [
    { key: 'seller.conversation.handed_off', title: 'Диалог ждёт менеджера' },
    { key: 'seller.promise.overdue', title: 'Обещание просрочено' },
    { key: 'seller.unknown_question.logged', title: 'Неизвестный вопрос' },
    { key: 'seller.supervisor.completed', title: 'Доклад готов' },
    { key: 'seller.supervisor.blocking_issue', title: 'Блокирующая проблема в докладе' },
    { key: 'seller.channel.disconnected', title: 'Канал отключился' },
    { key: 'seller.quota.threshold_reached', title: 'Достигнут порог лимита' },
  ],
  vision: [
    { key: 'vision.measurement.completed', title: 'Замер готов' },
    { key: 'vision.clarification.required', title: 'Требуются уточнения' },
    { key: 'vision.report.updated', title: 'Отчёт обновлён' },
    { key: 'vision.action.overdue', title: 'Действие просрочено' },
    { key: 'vision.competitor.top5_added', title: 'Новый конкурент в топ-5' },
    { key: 'vision.visibility.dropped', title: 'Видимость снизилась' },
  ],
  shell: [
    { key: 'shell.member.invited', title: 'Участник приглашён' },
    { key: 'ai.budget.threshold_reached', title: 'Достигнут порог бюджета ИИ' },
  ],
};
const profile = {
  name: owner.name,
  email: owner.email,
  phone: '+7 999 123-45-67',
  timezone: null,
  effective_timezone: 'Europe/Moscow',
  brand_timezone: 'Europe/Moscow',
  language: 'ru',
  avatar_url: null,
  role: 'owner',
  organization_name: 'Союз Пир',
  pending_email: params.has('pending') ? 'new@example.test' : null,
  email_change_available: true,
  email_confirmation_secure: !params.has('insecureCanonical'),
};
const sessions = [
  {
    id: 'opaque-session-one',
    device: 'Chrome · Windows',
    ip_address: '127.0.0.1',
    last_active_at: '2026-10-31T09:00:00Z',
    expires_at: '2026-11-01T09:00:00Z',
    is_current: true,
  },
  {
    id: 'opaque-session-two',
    device: 'Safari · iPhone',
    ip_address: '192.0.2.42',
    last_active_at: '2026-10-31T06:20:00Z',
    expires_at: '2026-11-01T06:20:00Z',
    is_current: false,
  },
];
const security = {
  timezone: 'Europe/Moscow',
  mfa_enabled: params.has('enabled'),
  sessions: params.has('empty') ? [] : sessions,
  setup: params.has('setup')
    ? {
        secret: 'JBSWY3DPEHPK3PXP',
        otpauth_url:
          'otpauth://totp/souzpir:ivan%40example.test?secret=JBSWY3DPEHPK3PXP&issuer=souzpir&algorithm=SHA1&digits=6&period=30',
        expires_at: '2026-10-31T09:10:00Z',
      }
    : null,
};
const notificationSettings = {
  events: params.has('empty')
    ? []
    : Object.entries(titles).flatMap(([group, events]) =>
        events.map((event, i) => ({
          ...event,
          group,
          eligible: true,
          channels: {
            cabinet: true,
            email: i % 2 === 0,
            telegram: params.has('connected') && i % 2 === 1,
          },
        })),
      ),
  telegram: {
    configured: !params.has('unconfigured'),
    connected: params.has('connected'),
    pending: params.has('pending'),
    pending_label: params.has('pending') ? 'Иван Петров (@ivan_test)' : null,
    link_available: false,
    expires_at: null,
  },
  timezone: 'Europe/Moscow',
  timingAvailable: false,
  timingDraftEditable: true,
  timingDraft: { quiet_start: null, quiet_end: null, digest_time: null },
  timingRevision: 'a'.repeat(64),
  testAvailable: true,
  testChannels: { cabinet: true, email: false, telegram: false },
  testNotifications: [],
};
const pageData =
  screen === 'notifications'
    ? { notificationSettings }
    : screen === 'security'
      ? { security }
      : {
          profile,
          emailConfirmationUrl: params.has('confirm') ? '/profile/email/confirm/01J00000000000000000000009?expires=1&signature=fixture' : undefined,
          profileBrandRevision: 'a'.repeat(64),
          timezones: [
            'Europe/Moscow',
            'Europe/Kaliningrad',
            'Asia/Yekaterinburg',
            'Asia/Vladivostok',
            'UTC',
          ],
        };
const translations = Object.fromEntries(
  Object.entries(profileCopy).map(([key, value]) => [`Shell/Profile/ui.${key}`, value]),
);
const shared = {
  ...pageData,
  mfaEnabled: false,
  canOpenJournal: true,
  status: params.has('oldSecurityStatus') ? 'Пароль изменён. Другие сессии завершены.' : null,
  auth: { user: owner },
  organization: { id: '01J00000000000000000000001', name: 'Союз Пир' },
  brand: { id: '01J00000000000000000000004', name: 'Пиротехника · праздничные события', theme },
  brands: [{ id: '01J00000000000000000000004', name: 'Пиротехника · праздничные события' }],
  modules: { seller: true, vision: true },
  can: {
    'org.read': true,
    'team.read': true,
    'billing.read': true,
    'integrations.read': true,
    'profile.own': true,
    'seller.dialogs.read': true,
    'seller.customers.read': true,
    'seller.knowledge.read': true,
    'seller.settings.read': true,
    'vision.report.read': true,
    'vision.measure.read': true,
  },
  unread: null,
  request_id: 'fixture',
  theme,
  translations,
  ziggy: {
    routes: { profile: { uri: 'profile', methods: ['GET'] } },
    url: location.origin,
    port: null,
    defaults: {},
    location: { host: location.host, pathname: location.pathname, search: '' },
  },
  errors: {},
};
const script = document.createElement('script');
script.type = 'application/json';
script.dataset.page = 'app';
script.textContent = JSON.stringify({
  component: 'Fixture',
  props: shared,
  url: screen === 'ready' ? '/auth/ready' : screen === 'switch' ? '/dashboard' : screen === 'profile' ? '/profile' : `/profile/${screen}`,
  version: 'fixture',
  clearHistory: false,
  encryptHistory: false,
});
document.body.append(script);
// This isolated visual fixture has no authenticated backend. Simulate only
// the profile's initial read-back verification; actual HTTP authorization is
// covered by the separate PHP race tests.
if (screen === 'profile') {
  const originalFetch = window.fetch.bind(window);
  window.fetch = (input, init) => {
    if (typeof input === 'string' && input === '/profile' && (!init?.method || init.method === 'GET')) {
      return Promise.resolve(new Response(JSON.stringify({ props: { profileBrandRevision: 'a'.repeat(64) } }), {
        status: 200, headers: { 'Content-Type': 'application/json', 'X-Inertia': 'true' },
      }));
    }
    return originalFetch(input, init);
  };
}
async function mount() {
  await createInertiaApp({
    resolve: () =>
      screen === 'security' ? Security : screen === 'notifications' ? Notifications : screen === 'ready' ? Ready : screen === 'switch' ? SwitchFixture : Profile,
    setup: ({ el, App, props }) => createRoot(el).render(<App {...props} />),
  });
}
void mount();
