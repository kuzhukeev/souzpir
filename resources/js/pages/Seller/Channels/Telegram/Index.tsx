import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import CabinetLayout from '@/layouts/CabinetLayout';

type CardItem = { key:string; types:string[]; name:string; status:string; href?:string };
type Channel = { id: string; name: string; type: string; status: string; mode: string; dialogs7d: number; lastActivity?: string | null };
const modes: Record<string, string> = { bot: 'Автономно', suggest: 'Подсказки', manager: 'Выключен' };
const statuses: Record<string, string> = { active: 'Подключён', unconfigured: 'Не настроен', disabled: 'Отключён', error: 'Ошибка', planned: 'Скоро' };

export default function Index({ channels, canManage, status, siteOrigins = [], siteEnabled = false }: { channels: Channel[]; canManage: boolean; status?: string | null; siteOrigins?: string[]; siteEnabled?: boolean }) {
  const types: CardItem[] = [
    {key:'widget',types:['widget','site','website'],name:'Сайт',status:'Настройка виджета',href:'/seller/channels/widget'},
    {key:'telegram',types:['telegram'],name:'Telegram',status:'Не подключён',href:'/seller/channels/telegram'},
    {key:'whatsapp',types:['whatsapp'],name:'WhatsApp',status:'Скоро'},
    {key:'vk',types:['vk'],name:'ВКонтакте',status:'Скоро'},
    {key:'email',types:['email'],name:'Почта',status:'Не подключена',href:'/seller/channels/email'},
    {key:'api',types:['api'],name:'Входящие формы и API',status:'Не подключены',href:'/seller/channels/api'},
  ];
  const cards = types.flatMap<{key:string;channel:Channel|null;item:CardItem}>(item => { const matches=channels.filter(channel=>item.types.includes(channel.type));return matches.length ? matches.map(channel=>({key:channel.id,channel,item})) : [{key:item.key,channel:null,item}]; });
  cards.push(...channels.filter(channel=>!types.some(item=>item.types.includes(channel.type))).map(channel=>({key:channel.id,channel,item:{key:channel.type,types:[channel.type],name:channel.name,status:''}})));
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [confirmStop, setConfirmStop] = useState(false);
  const saveMode = (channel: Channel, mode: string) => {
    setError(''); setBusy(true);
    router.patch(`/seller/channels/${channel.id}/mode`, { mode }, {
      onFinish: () => setBusy(false),
      onError: (errors) => setError(Object.values(errors)[0] ?? 'Не удалось изменить режим.'),
      onNetworkError: () => { setError('Нет связи. Проверьте режим перед повтором.'); return false; },
    });
  };
  const stop = () => {
    setError(''); setBusy(true);
    router.post('/seller/channels/stop', { confirm: true }, {
      onFinish: () => { setBusy(false); setConfirmStop(false); },
      onError: (errors) => setError(Object.values(errors)[0] ?? 'Не удалось остановить ботов.'),
      onNetworkError: () => { setError('Нет связи. Проверьте каналы перед повтором.'); return false; },
    });
  };
  return <CabinetLayout breadcrumbs={[{ label: 'Главная', href: '/dashboard' }, { label: 'Каналы' }]}>
    <Head title="Каналы" />
    <main className="mx-auto max-w-5xl pb-12">
      <h1 className="text-3xl font-bold">Каналы</h1>
      <p className="mt-2 text-sm text-(--color-ink-muted)">Управляйте общением с клиентами выбранного бренда.</p>
      {status && <p role="status" className="mt-4 rounded-lg bg-(--color-success-soft) p-3 text-sm">{status}</p>}
      {error && <p role="alert" className="mt-4 rounded-lg bg-(--color-danger-soft) p-3 text-sm">{error}</p>}
      {canManage && <button type="button" onClick={() => setConfirmStop(true)} className="mt-6 min-h-11 cursor-pointer rounded-lg border border-(--color-danger-ink) bg-(--color-danger-soft) px-4 text-sm font-semibold text-(--color-danger-ink)">Остановить всех ботов</button>}
      <section className="mt-5 rounded-xl bg-(--color-surface-muted) p-4 text-sm"><h2 className="font-semibold">Режимы каналов</h2><p className="mt-2">Автономно — бот отвечает сам · Подсказки — ответ утверждает менеджер · Выключен — бот не отвечает.</p></section>
      {confirmStop && <div role="dialog" aria-modal="true" aria-labelledby="stop-title" className="mt-4 rounded-xl border border-(--color-border) bg-(--color-surface) p-5 shadow-(--shadow-card)">
        <h2 id="stop-title" className="text-lg font-bold">Остановить всех ботов?</h2>
        <p className="mt-2 text-sm">Все каналы в автономном режиме перейдут в режим «Выключен».</p>
        <div className="mt-4 flex gap-3"><button type="button" disabled={busy} onClick={stop} className="min-h-11 cursor-pointer rounded-lg bg-(--color-danger-soft) px-4 text-sm font-semibold">Остановить</button><button type="button" onClick={() => setConfirmStop(false)} className="min-h-11 cursor-pointer rounded-lg border border-(--color-border) px-4 text-sm">Отмена</button></div>
      </div>}
      {channels.length===0 && <p className="mt-6 text-sm text-(--color-ink-muted)">Каналы ещё не подключены. Выберите канал для настройки.</p>}
      <div className="mt-6 grid gap-4 md:grid-cols-2">{cards.map(({key,channel,item})=><section key={key} className="rounded-2xl border border-(--color-border) bg-(--color-surface) p-5 shadow-(--shadow-card)"><h2 className="text-xl font-bold">{channel?.name || item.name}</h2>{item.key === 'widget' && <p className="mt-2 text-sm break-words">Адреса сайта: {siteOrigins.length ? siteOrigins.join(', ') : 'не указаны'}</p>}{item.key === 'widget' && !channel && <p className="mt-2 text-sm">{siteEnabled ? 'Виджет включён' : 'Виджет отключён'} · Режим и число диалогов недоступны</p>}{channel ? <><p className="mt-2 text-sm">{statuses[channel.status] ?? channel.status}</p><p className="mt-1 text-sm">Диалогов за 7 дней: {channel.dialogs7d}</p><p className="mt-1 text-sm text-(--color-ink-muted)">Последняя активность: {channel.lastActivity ? new Date(channel.lastActivity).toLocaleString('ru-RU') : 'нет данных'}</p><fieldset className="mt-4"><legend className="text-sm font-semibold">Режим</legend><div className="mt-2 flex flex-wrap gap-1">{Object.entries(modes).map(([value,label])=><button type="button" key={value} aria-pressed={channel.mode===value} disabled={!canManage||busy||item.key==='widget'} onClick={()=>saveMode(channel,value)} className={`min-h-11 cursor-pointer rounded-lg border px-3 text-sm disabled:opacity-50 ${channel.mode===value?'border-(--color-link) bg-(--color-surface-muted)':'border-(--color-border)'}`}>{label}</button>)}</div>{item.key==='widget' && <p className="mt-2 text-sm text-(--color-ink-muted)">Управление режимом недоступно. Новые обращения виджета поступают в диалоги для менеджера.</p>}</fieldset></>:<p className="mt-2 text-sm text-(--color-ink-muted)">{item.status}</p>}{'href' in item && item.href && <Link href={item.href} className="mt-4 inline-flex min-h-11 cursor-pointer items-center text-sm font-semibold text-(--color-link)">Настроить →</Link>}</section>)}</div>
    </main>
  </CabinetLayout>;
}
