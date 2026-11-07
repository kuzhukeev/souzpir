import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import { CalendarDays, Link2, Mail, Store } from 'lucide-react';
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
    {/* Brand marks: Simple Icons, CC0 — https://simpleicons.org/ */}
    {section === 'vk' ? <svg aria-hidden="true" viewBox="0 0 24 24" className="mx-auto size-24 fill-[#0077ff]"><path d="M15.684 0H8.316C1.592 0 0 1.592 0 8.316v7.368C0 22.408 1.592 24 8.316 24h7.368C22.408 24 24 22.408 24 15.684V8.316C24 1.592 22.391 0 15.684 0zm3.692 17.123h-1.744c-.66 0-.864-.525-2.05-1.727-1.033-1-1.49-1.135-1.744-1.135-.356 0-.458.102-.458.593v1.575c0 .424-.135.678-1.253.678-1.846 0-3.896-1.118-5.335-3.202C4.624 10.857 4.03 8.57 4.03 8.096c0-.254.102-.491.593-.491h1.744c.44 0 .61.203.78.677.863 2.49 2.303 4.675 2.896 4.675.22 0 .322-.102.322-.66V9.721c-.068-1.186-.695-1.287-.695-1.71 0-.204.17-.407.44-.407h2.744c.373 0 .508.203.508.643v3.473c0 .372.17.508.271.508.22 0 .407-.136.813-.542 1.254-1.406 2.151-3.574 2.151-3.574.119-.254.322-.491.763-.491h1.744c.525 0 .644.27.525.643-.22 1.017-2.354 4.031-2.354 4.031-.186.305-.254.44 0 .78.186.254.796.779 1.203 1.253.745.847 1.32 1.558 1.473 2.05.17.49-.085.744-.576.744z"/></svg> : <div aria-hidden="true" className="mx-auto grid size-32 place-items-center rounded-full bg-green-50 text-[#25d366]"><svg viewBox="0 0 24 24" className="size-16 fill-current"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/></svg></div>}
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
