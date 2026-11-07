import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import { CalendarDays, Link2, Mail, MessageCircle, Phone, Store } from 'lucide-react';
import { Badge, Button, Switch } from '@/components/ui';
import CabinetLayout from '@/layouts/CabinetLayout';
import { focus } from '@/components/ui/styles';
import { Interest } from '@/features/Seller/Channels/Planned/Interest';
import { plannedRequest } from '@/features/Seller/Channels/Planned/request';
import type { PlannedItem, PlannedProps } from '@/types/Seller/Channels/Planned';

const panel = 'rounded-2xl border border-(--color-border) bg-(--color-surface) p-6 shadow-(--shadow-card)';
const icon = 'grid size-20 shrink-0 place-items-center rounded-full bg-(--color-info-soft) text-(--color-link)';
const titles = {
  vk: ['ВКонтакте', 'Канал для сообщений сообщества'],
  whatsapp: ['WhatsApp', 'Канал находится в разработке'],
  calendar: ['Почта и календарь', 'Настройте напоминания и будущую запись на звонок'],
  planned: ['Будущие интеграции', 'Возможности, которые пока недоступны'],
} as const;

function Channel({ item, section, canManage, available, brandId }: { item: PlannedItem; section: 'vk' | 'whatsapp'; canManage: boolean; available: boolean; brandId: string }) {
  return <section aria-labelledby="channel-title" className={`${panel} mx-auto mt-10 max-w-3xl text-center sm:px-10 sm:py-12`}>
    {section === 'vk' ? <div aria-hidden="true" className="mx-auto grid size-24 place-items-center rounded-3xl bg-blue-600 text-3xl font-bold text-white">VK</div> : <div aria-hidden="true" className="relative mx-auto grid size-32 place-items-center rounded-full bg-green-50 text-green-600"><MessageCircle size={68} /><Phone size={28} className="absolute" /></div>}
    <div className={`mt-6 flex flex-wrap items-center justify-center gap-3 ${section === 'whatsapp' ? 'flex-col' : ''}`}><h2 id="channel-title" className="text-2xl font-bold">{section === 'vk' ? 'ВКонтакте' : 'Подключение WhatsApp'}</h2><Badge tone="warning">Скоро</Badge></div>
    <p className="mt-4 text-base text-(--color-ink-muted)">{section === 'vk' ? 'Подключение канала пока недоступно.' : 'Подключение планируется через WhatsApp Business API (WABA) с помощью провайдера.'}</p>
    {section === 'vk' && <div className="my-6 border-t border-(--color-border) pt-6 text-left sm:mx-auto sm:max-w-sm"><h3 className="text-lg font-bold">Что понадобится</h3><p className="mt-3 flex items-center gap-3"><Link2 aria-hidden="true" size={22} className="text-(--color-link)" />Токен сообщества ВКонтакте</p></div>}
    <div className="mt-6"><Interest item={item} canManage={canManage} available={available} brandId={brandId} /></div>
    {section === 'whatsapp' && <div className="mt-8 border-t border-(--color-border) pt-5"><Link href="/seller/channels" aria-label="Вернуться к каналам" className={`inline-flex min-h-10 cursor-pointer items-center text-sm font-semibold text-(--color-link) ${focus}`}><span aria-hidden="true" className="mr-2">←</span>Вернуться к каналам</Link></div>}
  </section>;
}

function Calendar({ planned }: Pick<PlannedProps, 'planned'>) {
  const [enabled, setEnabled] = useState(planned.reminders.enabled);
  const [original, setOriginal] = useState(planned.reminders.enabled);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState('');
  const [error, setError] = useState('');
  const calendar = planned.items.find((item) => item.key === 'calendar');
  async function save() {
    setBusy(true);
    setError('');
    setNotice('');
    try {
      await plannedRequest('/seller/planned/reminders', 'PUT', { enabled }, planned.brandId);
      setOriginal(enabled);
      setNotice('Настройки сохранены.');
    } catch (failure) {
      setError(failure instanceof Error ? failure.message : 'Не удалось сохранить. Попробуйте ещё раз.');
    } finally { setBusy(false); }
  }
  return <div className="space-y-5">
    <section className={`${panel} flex flex-wrap items-start gap-6 sm:gap-10 sm:p-8`} aria-labelledby="calendar-title">
      <div aria-hidden="true" className={`${icon} sm:size-28`}><CalendarDays size={48} /></div>
      <div className="min-w-0 flex-1"><div className="flex flex-wrap items-center gap-3"><h2 id="calendar-title" className="text-2xl font-bold">Запись на звонок</h2><Badge tone="warning">Скоро</Badge></div><p className="mt-3 text-base text-(--color-ink-muted)">Бот сможет записывать клиента на звонок в свободный слот менеджера.</p>{calendar && <div className="mt-5"><Interest item={calendar} canManage={planned.canManage} available={planned.interestAvailable} brandId={planned.brandId} /></div>}</div>
    </section>
    <section className={`${panel} flex flex-wrap items-start gap-6 sm:gap-10 sm:p-8`} aria-labelledby="reminders-title">
      <div aria-hidden="true" className={`${icon} sm:size-28`}><Mail size={48} /></div>
      <div className="min-w-0 flex-1"><h2 id="reminders-title" className="text-2xl font-bold">Напоминания о договорённостях</h2><div className="mt-5 max-w-2xl [&>button]:w-full [&>button]:flex-row-reverse [&>button]:justify-between [&>button]:gap-5 [&>button]:text-left"><Switch label="Напоминания о договорённостях на почту менеджеру" checked={enabled} onChange={setEnabled} disabled={!planned.canManage || !planned.reminders.available || busy} /></div><p className="mt-3 text-sm text-(--color-ink-muted)">{planned.reminders.reason || 'Напоминания пока недоступны.'}</p>{planned.reminders.available && <p className="mt-1 text-sm text-(--color-ink-muted)">Менеджер может настроить уведомления в профиле.</p>}</div>
    </section>
    <div className="flex flex-wrap items-center justify-end gap-3"><span role="status" className="text-sm text-(--color-success-ink)">{notice}</span>{error && <span role="alert" className="text-sm text-(--color-danger-ink)">{error}</span>}<Button onClick={() => void save()} loading={busy} disabled={!planned.canManage || !planned.reminders.available || enabled === original}>Сохранить изменения</Button></div>
  </div>;
}

function Integrations({ planned }: Pick<PlannedProps, 'planned'>) {
  const items = planned.items.filter((item) => !['vk', 'whatsapp', 'calendar'].includes(item.key));
  if (!items.length) return <p role="status" className={`${panel} text-(--color-ink-muted)`}>Будущие интеграции пока не указаны.</p>;
  return <section aria-label="Будущие интеграции"><ul className="grid gap-4 md:grid-cols-2">{items.map((item) => <li key={item.key} className={panel}><div className="flex flex-wrap items-center gap-3"><Store size={22} aria-hidden="true" className="text-(--color-link)" /><h2 className="text-xl font-bold">{item.label}</h2><Badge tone="warning">Скоро</Badge></div><p className="mt-3 text-sm text-(--color-ink-muted)">{item.description}</p><div className="mt-5"><Interest item={item} canManage={planned.canManage} available={planned.interestAvailable} brandId={planned.brandId} /></div></li>)}</ul></section>;
}

export default function Index({ section, planned }: PlannedProps) {
  const [title, subtitle] = titles[section];
  const item = planned.items.find((entry) => entry.key === section);
  return <CabinetLayout brandSwitchReady breadcrumbs={[{ label: 'Продавец', href: '/seller' }, { label: section === 'vk' || section === 'whatsapp' ? 'Каналы' : 'Интеграции', href: section === 'vk' || section === 'whatsapp' ? '/seller/channels' : '/seller/integrations/planned' }, { label: title }]}>
    <Head title={title} />
    <div className="mx-auto max-w-[1440px] space-y-6"><header><h1 className="text-3xl font-bold leading-tight">{title}</h1><p className="mt-2 text-base text-(--color-ink-muted)">{subtitle}</p></header>
      {(section === 'vk' || section === 'whatsapp') && (item ? <Channel item={item} section={section} canManage={planned.canManage} available={planned.interestAvailable} brandId={planned.brandId} /> : <p role="alert" className={panel}>Не удалось загрузить данные канала.</p>)}
      {section === 'calendar' && <Calendar planned={planned} />}
      {section === 'planned' && <nav aria-label="Будущие каналы и напоминания" className="flex flex-wrap gap-3">
        <Link href="/seller/channels/vk" className={`inline-flex min-h-10 cursor-pointer items-center rounded-lg bg-(--color-surface) px-4 text-sm font-semibold text-(--color-link) ${focus}`}>ВКонтакте · Скоро</Link>
        <Link href="/seller/channels/whatsapp" className={`inline-flex min-h-10 cursor-pointer items-center rounded-lg bg-(--color-surface) px-4 text-sm font-semibold text-(--color-link) ${focus}`}>WhatsApp · Скоро</Link>
        <Link href="/seller/integrations/calendar" className={`inline-flex min-h-10 cursor-pointer items-center rounded-lg bg-(--color-surface) px-4 text-sm font-semibold text-(--color-link) ${focus}`}>Почта и календарь · Скоро</Link>
      </nav>}
      {section === 'planned' && <Integrations planned={planned} />}
      {section !== 'planned' && <nav aria-label="Переходы к каналам и интеграциям" className="flex flex-wrap gap-x-6 gap-y-1">
        {section === 'vk' && <Link href="/seller/channels" className={`inline-flex min-h-10 cursor-pointer items-center text-sm font-semibold text-(--color-link) ${focus}`}>Вернуться к каналам</Link>}
        <Link href="/seller/integrations/planned" className={`inline-flex min-h-10 cursor-pointer items-center text-sm font-semibold text-(--color-link) ${focus}`}>Другие будущие интеграции</Link>
      </nav>}
    </div>
  </CabinetLayout>;
}
