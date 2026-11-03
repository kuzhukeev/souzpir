import { Head, router, usePage } from '@inertiajs/react';
import { useEffect, useState, type FormEvent } from 'react';
import CabinetLayout from '@/layouts/CabinetLayout';

type Item = { id: string; name: string; mode: string; stage: string; outcome: string | null; channel: string; last_message: string | null; updated_at: string; assigned_user_id: string | null };
type Message = { id: string; role: string; text: string; created_at: string; meta: Record<string, string | null> };
type Detail = Item & { summary: string | null; email: string | null; phone: string | null; slots: { key: string; label: string; value: string | null }[]; messages: Message[]; tasks: { id: string; type: string; status: string; due_at: string | null }[] };
type Props = { dialogs: Item[]; dialog: Detail | null; mineCounts?: { waiting: number; active: number; closed_today: number }; managerOptions?: {id: string; name: string}[]; pagination?: {page: number; pages: number; total: number}; filters: { mode: string; search: string; sort?: string; channel?: string; stage?: string; manager?: string; page?: number } };

const modeNames: Record<string, string> = { all: 'Все', mine: 'Мои диалоги', bot: 'У бота', suggest: 'Подсказки', manager: 'У менеджера', paused: 'Приостановлены' };
const roleNames: Record<string, string> = { client: 'Клиент', agent: 'Бот', manager: 'Менеджер', system: 'Событие' };
const metaNames: Record<string, string> = { intent: 'Тема', goal: 'Цель', tools: 'Действия', guard: 'Guard', model: 'Модель' };
const date = (value: string) => new Date(value).toLocaleString('ru-RU', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });

export default function Index({ dialogs, dialog, mineCounts = { waiting: 0, active: 0, closed_today: 0 }, managerOptions = [], pagination = {page: 1, pages: 1, total: 0}, filters }: Props) {
  const { can, brand } = usePage<{ can: Record<string, boolean>; brand: { id: string } | null }>().props;
  const [search, setSearch] = useState(filters.search);
  const [pending, setPending] = useState(false);
  const [error, setError] = useState('');
  const [cardOpen, setCardOpen] = useState(false);
  const [reviewMessage, setReviewMessage] = useState<string | null>(null);
  const [reviewType, setReviewType] = useState('unclear');
  const [reviewNote, setReviewNote] = useState('');
  const [betterText, setBetterText] = useState('');
  const [reviewSaved, setReviewSaved] = useState(false);
  const [previousDialogId, setPreviousDialogId] = useState(dialog?.id);
  const [previousBrandId, setPreviousBrandId] = useState(brand?.id);
  if (previousDialogId !== dialog?.id || previousBrandId !== brand?.id) {
    setPreviousDialogId(dialog?.id);
    setReviewMessage(null); setReviewNote(''); setBetterText(''); setReviewSaved(false);
  }
  if (previousBrandId !== brand?.id) {
    setPreviousBrandId(brand?.id);
    setSearch(filters.search); setCardOpen(false); setError('');
  }
  useEffect(() => {
    const timer = window.setInterval(() => {
      if (document.visibilityState === 'visible') router.reload({ only: ['dialogs', 'dialog', 'mineCounts', 'pagination', 'managerOptions'] });
    }, 15000);
    return () => window.clearInterval(timer);
  }, []);
  const saveReview = (event: FormEvent) => {
    event.preventDefault();
    if (!dialog || !reviewMessage) return;
    setPending(true); setError('');
    router.post('/seller/dialogs/' + dialog.id + '/messages/' + reviewMessage + '/reviews', { type: reviewType, note: reviewNote, better_text: betterText }, {
      preserveScroll: true,
      onSuccess: () => { setReviewMessage(null); setReviewNote(''); setBetterText(''); setReviewSaved(true); },
      onError: () => setError('Не удалось сохранить пометку. Проверьте введённые данные.'),
      onFinish: () => setPending(false),
    });
  };
  const filter = (mode: string, query = search, sort = filters.sort, changes: {channel?: string; stage?: string; manager?: string; page?: number} = {}) => {
    setPending(true);
    setError('');
    const channel = changes.channel ?? filters.channel; const stage = changes.stage ?? filters.stage; const manager = changes.manager ?? filters.manager; const page = changes.page ?? 1;
    router.get('/seller/dialogs', { mode, search: query, ...(sort ? { sort } : {}), ...(channel ? {channel} : {}), ...(stage ? {stage} : {}), ...(manager ? {manager} : {}), ...(page > 1 ? {page} : {}) }, { preserveState: true, onError: () => setError('Не удалось загрузить диалоги.'), onFinish: () => setPending(false) });
  };
  const open = (id: string) => {
    setPending(true);
    setError('');
    setCardOpen(false);
    router.get(`/seller/dialogs/${id}`, { mode: filters.mode, search: filters.search, ...(filters.sort ? { sort: filters.sort } : {}), ...(filters.channel ? {channel: filters.channel} : {}), ...(filters.stage ? {stage: filters.stage} : {}), ...(filters.manager ? {manager: filters.manager} : {}), ...(filters.page && filters.page > 1 ? {page: filters.page} : {}) }, { preserveState: true, onError: () => setError('Не удалось открыть диалог.'), onFinish: () => setPending(false) });
  };
  const changeMode = (action: 'take' | 'return') => {
    if (!dialog) return;
    setPending(true);
    setError('');
    router.post(`/seller/dialogs/${dialog.id}/mode`, { action }, { preserveScroll: true, onError: (errors) => setError(errors.action || 'Не удалось изменить режим диалога.'), onFinish: () => setPending(false) });
  };

  return <CabinetLayout breadcrumbs={[{ label: 'Продавец', href: '/seller' }, { label: 'Диалоги' }]}>
    <Head title="Диалоги" />
    <div className="mb-6"><h1 className="text-3xl font-bold">Диалоги</h1><p className="mt-2 text-sm text-(--color-ink-muted)">Сохранённые разговоры с клиентами по текущему бренду</p></div>
    {error && <div role="alert" className="mb-4 rounded-xl border border-red-300 bg-red-50 p-3 text-sm text-red-800 dark:bg-red-950 dark:text-red-200">{error}</div>}
    {filters.mode === 'mine' && <div className="mb-4 grid gap-3 sm:grid-cols-3" aria-label="Счётчики моих диалогов"><div className="rounded-xl border border-(--color-border) bg-(--color-surface) p-4 text-sm">Ждут ответа <strong>{mineCounts.waiting}</strong></div><div className="rounded-xl border border-(--color-border) bg-(--color-surface) p-4 text-sm">В работе <strong>{mineCounts.active}</strong></div><div className="rounded-xl border border-(--color-border) bg-(--color-surface) p-4 text-sm">Закрыты сегодня <strong>{mineCounts.closed_today}</strong></div></div>}
    <div className="grid min-w-0 gap-4 lg:grid-cols-[320px_minmax(0,1fr)] xl:grid-cols-[320px_minmax(0,1fr)_300px]">
      <section aria-label="Список диалогов" className={`${dialog ? 'hidden lg:block' : 'block'} min-w-0 rounded-2xl border border-(--color-border) bg-(--color-surface) shadow-(--shadow-card)`}>
        <div className="border-b border-(--color-border) p-4">
          <form onSubmit={(event: FormEvent) => { event.preventDefault(); filter(filters.mode); }} className="flex gap-2">
            <label className="sr-only" htmlFor="dialog-search">Поиск диалога</label>
            <input id="dialog-search" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Имя клиента" className="min-w-0 flex-1 rounded-lg border border-(--color-border) bg-(--color-surface) px-3 py-2 text-sm" />
            <button type="submit" disabled={pending} className="cursor-pointer rounded-lg bg-(--color-action) px-3 py-2 text-sm text-white disabled:opacity-50">Найти</button>
          </form>
          {<label className="mt-3 block text-sm">Сортировка<select aria-label="Сортировка" value={filters.sort ?? 'newest'} onChange={(event) => filter(filters.mode, search, event.target.value)} className="mt-1 block w-full cursor-pointer rounded-lg border border-(--color-border) bg-(--color-surface) px-3 py-2"><option value="newest">Сначала новые</option><option value="oldest">Сначала старые</option></select></label>}
          <div className="mt-3 grid gap-2"><label className="text-sm">Канал<select aria-label="Канал" value={filters.channel ?? ''} onChange={event=>filter(filters.mode,search,filters.sort,{channel:event.target.value})} className="mt-1 block w-full cursor-pointer rounded-lg border border-(--color-border) bg-(--color-surface) p-2"><option value="">Все каналы</option><option value="telegram">Telegram</option><option value="widget">Виджет</option><option value="api">API</option><option value="email">Почта</option><option value="whatsapp">WhatsApp</option></select></label><label className="text-sm">Этап<select aria-label="Этап" value={filters.stage ?? ''} onChange={event=>filter(filters.mode,search,filters.sort,{stage:event.target.value})} className="mt-1 block w-full cursor-pointer rounded-lg border border-(--color-border) bg-(--color-surface) p-2"><option value="">Все этапы</option>{[['open','Новый'],['qualify','Знакомство'],['need','Потребность'],['budget','Бюджет'],['close_contact','Контакт'],['followup','Напоминание'],['handoff','Передан'],['done','Завершён'],['lost','Потерян'],['spam','Спам']].map(([value,label])=><option key={value} value={value}>{label}</option>)}</select></label><label className="text-sm">Менеджер<select aria-label="Менеджер" value={filters.manager ?? ''} onChange={event=>filter(filters.mode,search,filters.sort,{manager:event.target.value})} className="mt-1 block w-full cursor-pointer rounded-lg border border-(--color-border) bg-(--color-surface) p-2"><option value="">Все менеджеры</option>{managerOptions.map(option=><option key={option.id} value={option.id}>{option.name}</option>)}</select></label></div>
          <div className="mt-3 flex flex-wrap gap-2" aria-label="Фильтр по режиму">{Object.entries(modeNames).map(([mode, label]) => <button key={mode} type="button" disabled={pending} onClick={() => filter(mode)} aria-pressed={filters.mode === mode} className={`cursor-pointer rounded-full border px-3 py-1 text-sm disabled:opacity-50 ${filters.mode === mode ? 'border-(--color-action) bg-(--color-action) text-white' : 'border-(--color-border)'}`}>{label}</button>)}</div>
        </div>
        {pending && <p role="status" className="px-4 pt-3 text-sm text-(--color-ink-muted)">Загрузка диалогов…</p>}
        {dialogs.length === 0 ? <p className="p-6 text-sm text-(--color-ink-muted)">Диалогов пока нет.</p> : <ul className="divide-y divide-(--color-border)">{dialogs.map((item) => <li key={item.id}>
          <button type="button" onClick={() => open(item.id)} aria-current={dialog?.id === item.id ? 'page' : undefined} className={`w-full cursor-pointer p-4 text-left hover:bg-(--color-surface-muted) ${dialog?.id === item.id ? 'bg-(--color-surface-muted)' : ''}`}>
            <span className="flex items-start justify-between gap-2"><strong className="min-w-0 truncate text-sm">{item.name}</strong><time className="shrink-0 text-xs text-(--color-ink-muted)">{date(item.updated_at)}</time></span>
            <span className="mt-1 block truncate text-sm text-(--color-ink-muted)">{item.last_message || 'Сообщений пока нет'}</span>
            <span className="mt-2 flex flex-wrap gap-2 text-xs"><span className="rounded-full bg-(--color-surface-muted) px-2 py-1">{item.channel}</span><span className="rounded-full bg-(--color-surface-muted) px-2 py-1">{modeNames[item.mode] || item.mode}</span><span className="rounded-full bg-(--color-surface-muted) px-2 py-1">{item.stage}</span></span>
          </button>
        </li>)}</ul>}
        {pagination.pages > 1 && <nav aria-label="Страницы диалогов" className="flex items-center justify-between gap-2 border-t border-(--color-border) p-4 text-sm"><button type="button" disabled={pending || pagination.page<=1} onClick={()=>filter(filters.mode,filters.search,filters.sort,{page:pagination.page-1})} className="cursor-pointer disabled:opacity-50">Назад</button><span>Страница {pagination.page} из {pagination.pages}</span><button type="button" disabled={pending || pagination.page>=pagination.pages} onClick={()=>filter(filters.mode,filters.search,filters.sort,{page:pagination.page+1})} className="cursor-pointer disabled:opacity-50">Вперёд</button></nav>}
      </section>

      <section aria-label="Диалог" className={`${dialog ? 'block' : 'hidden lg:block'} min-w-0 rounded-2xl border border-(--color-border) bg-(--color-surface) shadow-(--shadow-card)`}>
        {!dialog ? <div className="p-6 text-sm text-(--color-ink-muted)">Выберите диалог из списка.</div> : <>
          <header className="flex flex-wrap items-center justify-between gap-3 border-b border-(--color-border) p-4">
            <div className="min-w-0"><a href="/seller/dialogs" className="mb-2 inline-block cursor-pointer text-sm text-(--color-action) lg:hidden">← К списку</a><h2 className="truncate text-xl font-semibold">{dialog.name}</h2><p className="mt-1 text-sm text-(--color-ink-muted)">{dialog.channel} · {dialog.stage}{dialog.outcome ? ` · ${dialog.outcome}` : ''}</p></div>
            <div className="flex flex-wrap gap-2">{can['seller.dialogues.operate'] && <>{dialog.mode !== 'manager' || !dialog.assigned_user_id ? <button type="button" disabled={pending} onClick={() => changeMode('take')} className="cursor-pointer rounded-lg bg-(--color-action) px-3 py-2 text-sm text-white disabled:opacity-50">Забрать себе</button> : <button type="button" disabled={pending} onClick={() => changeMode('return')} className="cursor-pointer rounded-lg border border-(--color-border) px-3 py-2 text-sm disabled:opacity-50">Вернуть боту</button>}</>}<button type="button" onClick={() => setCardOpen(true)} className="cursor-pointer rounded-lg border border-(--color-border) px-3 py-2 text-sm xl:hidden">Карточка клиента</button></div>
          </header>
          {reviewSaved && <p role="status" className="px-4 pt-4 text-sm">Пометка сохранена.</p>}
          <ol className="space-y-4 p-4 md:p-6" aria-label="Сообщения">{dialog.messages.length === 0 ? <li className="text-sm text-(--color-ink-muted)">Сообщений пока нет.</li> : dialog.messages.map((message) => <li key={message.id} className={`max-w-[90%] rounded-xl p-4 text-sm ${message.role === 'client' ? 'border border-(--color-border) bg-(--color-surface-muted)' : message.role === 'system' ? 'mx-auto bg-(--color-surface-muted) text-center' : 'ml-auto bg-(--color-action) text-white'}`}>
            <div className="mb-1 flex flex-wrap gap-2 text-xs opacity-80"><strong>{roleNames[message.role] || message.role}</strong><time>{date(message.created_at)}</time></div><p className="whitespace-pre-wrap break-words">{message.text}</p>
            {message.role === 'agent' && can['seller.dialogues.operate'] && <button type="button" onClick={() => { setReviewMessage(message.id); setReviewSaved(false); }} className="mt-2 cursor-pointer underline">Пометить</button>}
            {reviewMessage === message.id && <form onSubmit={saveReview} className="mt-3 space-y-2 rounded-lg bg-(--color-surface) p-3 text-(--color-ink)">
              <label className="block">Причина<select value={reviewType} onChange={(event) => setReviewType(event.target.value)} className="mt-1 block w-full cursor-pointer rounded-lg border border-(--color-border) bg-(--color-surface) p-2"><option value="unclear">Непонятно</option><option value="bureaucratic">Канцелярит</option><option value="too_long">Слишком длинно</option><option value="wrong_fact">Неверный факт</option><option value="wrong_tone">Неверный тон</option><option value="other">Другое</option></select></label>
              <label className="block">Комментарий<textarea maxLength={2000} value={reviewNote} onChange={(event) => setReviewNote(event.target.value)} className="mt-1 block w-full rounded-lg border border-(--color-border) bg-(--color-surface) p-2" /></label>
              <label className="block">Как лучше ответить<textarea maxLength={5000} value={betterText} onChange={(event) => setBetterText(event.target.value)} className="mt-1 block w-full rounded-lg border border-(--color-border) bg-(--color-surface) p-2" /></label>
              <button disabled={pending} className="cursor-pointer rounded-lg bg-(--color-action) px-3 py-2 text-white disabled:opacity-50">Сохранить</button> <button type="button" onClick={() => setReviewMessage(null)} className="cursor-pointer px-3 py-2">Отмена</button>
            </form>}
            {message.role === 'agent' && Object.values(message.meta).some(Boolean) && <details className="mt-2 cursor-pointer text-xs"><summary>Подробности хода</summary><dl className="mt-2 space-y-1">{Object.entries(message.meta).filter(([, value]) => value).map(([key, value]) => <div key={key}><dt className="inline font-semibold">{metaNames[key] || key}: </dt><dd className="inline">{value}</dd></div>)}</dl></details>}
          </li>)}</ol>
          <div className="border-t border-(--color-border) p-4 text-sm text-(--color-ink-muted)">Отправка сообщений из кабинета пока недоступна.</div>
        </>}
      </section>

      {dialog && <><div onClick={() => setCardOpen(false)} className={`${cardOpen ? 'fixed inset-0 z-30 bg-black/40 xl:hidden' : 'hidden'}`} aria-hidden="true" /><aside aria-label="Карточка клиента" className={`${cardOpen ? 'fixed inset-y-0 right-0 z-40 w-[min(90vw,340px)] overflow-y-auto' : 'hidden'} min-w-0 rounded-2xl border border-(--color-border) bg-(--color-surface) p-4 shadow-(--shadow-card) xl:static xl:block xl:w-auto`}>
        <div className="mb-4 flex items-center justify-between"><h2 className="text-lg font-semibold">Карточка клиента</h2><button type="button" onClick={() => setCardOpen(false)} aria-label="Закрыть карточку" className="cursor-pointer text-lg xl:hidden">×</button></div>
        <p className="mb-4 text-sm text-(--color-ink-muted)">{dialog.phone || dialog.email || 'Контакты не указаны'}</p>
        <h3 className="mb-2 text-sm font-semibold">16 полей клиента</h3><dl className="space-y-2">{dialog.slots.map((slot) => <div key={slot.key} className="border-b border-(--color-border) pb-2 text-sm"><dt className="text-(--color-ink-muted)">{slot.label}</dt><dd className="break-words">{slot.value || 'Не известно'}</dd></div>)}</dl>
        <h3 className="mt-5 text-sm font-semibold">Краткое содержание</h3><p className="mt-2 whitespace-pre-wrap text-sm text-(--color-ink-muted)">{dialog.summary || 'Пока нет содержания.'}</p>
        <h3 className="mt-5 text-sm font-semibold">Задачи и обещания</h3>{dialog.tasks.length ? <ul className="mt-2 space-y-2 text-sm">{dialog.tasks.map((task) => <li key={task.id} className="rounded-lg bg-(--color-surface-muted) p-2">{task.type} · {task.status}{task.due_at ? ` · ${date(task.due_at)}` : ''}</li>)}</ul> : <p className="mt-2 text-sm text-(--color-ink-muted)">Задач пока нет.</p>}
      </aside></>}
    </div>
  </CabinetLayout>;
}
