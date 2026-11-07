import { Link, router, usePage } from '@inertiajs/react';
import { FileDown, FileSpreadsheet, Mail } from 'lucide-react';
import { useState } from 'react';

type Dialog = {
  id: string;
  client?: string;
  stage: string;
  channel: string;
  messages: number;
  has_contact?: boolean;
  handed_off?: boolean;
  last_activity: string;
  created_at: string;
};
type Props = {
  from: string;
  to: string;
  total: number;
  contact?: number;
  handed?: number;
  closed?: number;
  messageCount?: number;
  botCompleted?: number;
  dialogs?: Dialog[];
  hours?: Record<string, number>;
  unknownQuestions?: Record<string, number>;
  unknownDialogs?: number;
  triggerStats?: Record<string, { dialogs: number; with_contact: number }>;
  triggers?: Record<string, number>;
  topics: Record<string, number>;
  daily?: Record<string, number>;
  channel?: string;
  source?: string;
  manager?: string;
  managerOptions?: {id:string;name:string}[];
  canManage?: boolean;
  subscription?: {day:string; recipients:string[]} | null;
  managers?: { name: string; assigned: number; taken: number; closed: number }[];
};
const card = 'mt-5 rounded-xl border border-(--color-border) bg-(--color-surface) p-5';
const names: Record<string, string> = {
  unknown: 'Неизвестен',
  telegram: 'Telegram',
  widget: 'Сайт',
  api: 'API',
  open: 'Новый',
  qualify: 'Уточнение',
  need: 'Потребность',
  budget: 'Бюджет',
  close_contact: 'Контакт',
  followup: 'Дожим',
  handoff: 'Передан',
  lost: 'Потерян',
  done: 'Завершён',
  spam: 'Спам',
  delivery: 'Доставка',
  price: 'Цена',
  payment: 'Оплата',
  product: 'Товар',
};
const days = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
export default function Details({ data: a, activeTab }: { data: Props; activeTab?: string }) {
  const {brand} = usePage<{brand:{name:string}|null}>().props;
  const [reportType, setReportType] = useState('weekly');
  const [email, setEmail] = useState('');
  const [sending, setSending] = useState(false);
  const [day, setDay] = useState(a.subscription?.day ?? 'mon');
  const [recipients, setRecipients] = useState<string[]>(a.subscription?.recipients ?? []);
  const [recipientInput, setRecipientInput] = useState('');
  const [subBusy, setSubBusy] = useState(false);
  const [subStatus, setSubStatus] = useState('');
  const [sendStatus, setSendStatus] = useState('');
  const dialogs = a.dialogs ?? [];
  const longDialogs = dialogs.filter((d) => d.messages >= 8 && d.has_contact === false);
  const closedWithoutHandoff = dialogs.filter(
    (d) => d.stage === 'done' && d.handed_off === false,
  ).length;
  const hours = a.hours ?? {};
  const questions = Object.entries(a.unknownQuestions ?? {});
  return (
    <>
      {activeTab === undefined && (
        <nav
          aria-label="Подробные разделы аналитики"
          className="mt-5 flex flex-wrap gap-4 text-sm print:hidden"
        >
          <a className="cursor-pointer text-(--color-link)" href="#dialogs">
            Диалоги
          </a>
          <a className="cursor-pointer text-(--color-link)" href="#topics">
            Темы и вопросы
          </a>
          <a className="cursor-pointer text-(--color-link)" href="#report">
            Отчёт и PDF
          </a>
        </nav>
      )}
      <section
        hidden={activeTab !== undefined && activeTab !== 'dialogs'}
        id="dialogs"
        className={card}
      >
        <h2 className="text-xl font-bold">Аналитика диалогов</h2>
        <p className="mt-3 text-sm">
          Среднее число сообщений:{' '}
          {a.total
            ? ((a.messageCount ?? 0) / a.total).toLocaleString('ru-RU', {
                maximumFractionDigits: 1,
              })
            : 'Нет данных'}
          .
        </p>
        <p className="mt-2 text-sm">
          Закрыто без передачи менеджеру:{' '}
          {a.total ? `${closedWithoutHandoff} из ${a.total}` : 'Нет данных'}. Бот ответил сам до
          конца:{' '}
          {a.total && a.botCompleted != null
            ? `${Math.round((a.botCompleted / a.total) * 100)}%`
            : 'Нет данных'}{' '}
          (завершённые с ответом бота, без передачи и сообщений менеджера). Среднее время до
          контакта: нет данных — момент получения контакта не сохраняется.
        </p>
        <p className="mt-2 text-sm text-(--color-ink-muted)">
          Показаны диалоги, созданные за выбранный период, и все сохранённые сообщения этих
          диалогов.
        </p>
        {dialogs.length ? (
          <div className="mt-4 overflow-x-auto">
            <table className="w-full min-w-[600px] text-left text-sm">
              <thead>
                <tr>
                  <th className="p-2">Клиент</th>
                  <th className="p-2">Диалог</th>
                  <th className="p-2">Канал</th>
                  <th className="p-2">Стадия</th>
                  <th className="p-2">Сообщений</th>
                  <th className="p-2">Последнее изменение</th>
                </tr>
              </thead>
              <tbody>
                {dialogs.map((d) => (
                  <tr key={d.id} className="border-t border-(--color-border)">
                    <td className="p-2">{d.client || 'Не указан'}</td>
                    <td className="p-2">
                      <Link
                        className="cursor-pointer text-(--color-link)"
                        href={`/seller/dialogs/${d.id}`}
                      >
                        Открыть диалог
                      </Link>
                    </td>
                    <td className="p-2">{names[d.channel] ?? d.channel}</td>
                    <td className="p-2">{names[d.stage] ?? d.stage}</td>
                    <td className="p-2">{d.messages}</td>
                    <td className="p-2">{new Date(d.last_activity).toLocaleString('ru-RU')}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : (
          <p className="mt-4 text-sm">За выбранный период диалогов нет.</p>
        )}
        <h3 className="mt-5 font-semibold">Длинные диалоги без контакта</h3>
        <p className="mt-1 text-sm text-(--color-ink-muted)">
          Не менее восьми сохранённых сообщений.
        </p>
        {longDialogs.length ? (
          <ul className="mt-2 space-y-2 text-sm">
            {longDialogs.map((d) => (
              <li key={d.id}>
                {d.client || 'Клиент не указан'} ·{' '}
                <Link
                  className="cursor-pointer text-(--color-link)"
                  href={`/seller/dialogs/${d.id}`}
                >
                  Открыть диалог
                </Link>{' '}
                · {names[d.channel] ?? d.channel} · {d.messages} сообщений ·{' '}
                {new Date(d.last_activity).toLocaleString('ru-RU')}
              </li>
            ))}
          </ul>
        ) : (
          <p className="mt-2 text-sm">Таких диалогов нет.</p>
        )}
        <h3 className="mt-5 font-semibold">Диалоги по дню недели и часу</h3>
        <p className="mt-2 text-sm text-(--color-ink-muted)">
          Время создания диалога в часовом поясе бренда.
        </p>
        <div className="mt-3 overflow-x-auto">
          <table className="min-w-[800px] text-center text-sm">
            <thead>
              <tr>
                <th>День</th>
                {Array.from({ length: 24 }, (_, h) => (
                  <th key={h} className="px-2">
                    {h}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {days.map((day, i) => (
                <tr key={day}>
                  <th className="p-2">{day}</th>
                  {Array.from({ length: 24 }, (_, h) => {
                    const n = hours[`${i + 1}:${String(h).padStart(2, '0')}`] ?? 0;
                    return (
                      <td
                        key={h}
                        className={`p-2 ${n ? 'bg-(--color-brand-primary-soft) font-semibold' : ''}`}
                      >
                        {n || '—'}
                      </td>
                    );
                  })}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>
      <section
        hidden={activeTab !== undefined && activeTab !== 'topics'}
        id="topics"
        className={card}
      >
        <h2 className="text-xl font-bold">Темы и вопросы</h2>
        <h3 className="mt-4 font-semibold">Темы сообщений</h3>
        {Object.keys(a.topics).length ? (
          <ul className="mt-2 space-y-2 text-sm">
            {Object.entries(a.topics).map(([topic, count]) => (
              <li key={topic}>
                {names[topic] ?? topic}: {count}
              </li>
            ))}
          </ul>
        ) : (
          <p className="mt-2 text-sm">Сохранённых тем нет.</p>
        )}
        <h3 className="mt-5 font-semibold">Неизвестные вопросы</h3>
        <p className="mt-2 text-sm">
          Не знаю:{' '}
          {a.total
            ? `${Math.round(((a.unknownDialogs ?? 0) / a.total) * 100)}% диалогов`
            : 'нет данных'}{' '}
          (по сохранённым задачам).
        </p>
        {questions.length ? (
          <ul className="mt-2 space-y-2 text-sm">
            {questions.map(([q, count]) => (
              <li key={q} className="rounded-lg bg-(--color-surface-muted) p-3">
                {q} · {count}{' '}
                <Link
                  href="/seller/knowledge"
                  className="ml-2 cursor-pointer text-(--color-link) underline"
                >
                  В базу знаний →
                </Link>
              </li>
            ))}
          </ul>
        ) : (
          <p className="mt-2 text-sm">Неизвестных вопросов за период нет.</p>
        )}
        <h3 className="mt-5 font-semibold">Приёмы продаж</h3>
        {Object.keys(a.triggers ?? {}).length ? (
          <ul className="mt-2 text-sm">
            {Object.entries(a.triggers ?? {}).map(([trigger, count]) => (
              <li key={trigger}>
                {trigger}: {count}
                {a.triggerStats?.[trigger] &&
                  ` · контакт в ${a.triggerStats[trigger].with_contact} из ${a.triggerStats[trigger].dialogs} диалогов · конверсия ${a.triggerStats[trigger].dialogs ? Math.round((a.triggerStats[trigger].with_contact / a.triggerStats[trigger].dialogs) * 100) : 0}%`}
              </li>
            ))}
          </ul>
        ) : (
          <p className="mt-2 text-sm">Сохранённых сработок нет.</p>
        )}
        <Link
          href="/seller/knowledge"
          className="mt-3 inline-flex min-h-11 cursor-pointer items-center text-sm text-(--color-link)"
        >
          В базу знаний →
        </Link>
      </section>
      <section
        hidden={activeTab !== undefined && activeTab !== 'report'}
        id="report"
        className={card}
      >
        <h2 className="text-xl font-bold">Отчёт</h2>
        <fieldset className="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4 print:hidden"><legend className="text-sm font-semibold">Выберите отчёт</legend>{[
          ['weekly','Сводка владельцу','Ключевые показатели за период'],
          ['dialogs','Диалоги','Ход и результаты диалогов'],
          ['topics','Темы и вопросы','Темы обращений и пробелы'],
          ...(a.canManage ? [['managers','Воронка по менеджерам','Показатели работы команды']] : []),
        ].map(([value,title,description])=><label key={value} className={`flex min-h-24 cursor-pointer items-start gap-3 rounded-xl border p-4 text-sm ${reportType===value?'border-(--color-link) bg-(--color-brand-primary-soft)':'border-(--color-border) bg-(--color-surface)'}`}><input type="radio" name="report-card" checked={reportType===value} onChange={()=>setReportType(value)} className="mt-1 size-4 cursor-pointer accent-(--color-link)"/><span><span className="block font-semibold">{title}</span><span className="mt-1 block text-(--color-ink-muted)">{description}</span></span></label>)}</fieldset>
        <label className="sr-only focus-within:not-sr-only print:hidden">
          Тип отчёта
          <select
            value={reportType}
            onChange={(event) => setReportType(event.target.value)}
            className="mt-2 block min-h-11 cursor-pointer rounded-lg border border-(--color-border) bg-(--color-surface) px-3"
          >
            <option value="weekly">Сводка владельцу</option>
            <option value="dialogs">Диалоги</option>
            {a.canManage && <option value="managers">Воронка по менеджерам</option>}
            <option value="topics">Темы и вопросы</option>
          </select>
        </label>
        <div className="mt-4 grid gap-5 xl:grid-cols-[minmax(0,3fr)_minmax(18rem,2fr)]"><div className="min-w-0">
        <div className="mt-4 rounded-xl border border-(--color-border) bg-(--color-surface-muted) p-4"><p className="text-sm font-semibold text-(--color-link)">{brand?.name ?? 'Бренд'}</p><h3 className="mt-2 font-semibold">
          Предпросмотр:{' '}
          {reportType === 'weekly'
            ? 'Сводка владельцу'
            : reportType === 'dialogs'
              ? 'Диалоги'
              : reportType === 'managers'
                ? 'Воронка по менеджерам'
                : 'Темы и вопросы'}
        </h3>
        <p className="mt-2 text-sm">
          Период: {a.from} — {a.to}. Канал: {a.channel || 'все'}; источник:{' '}
          {a.source === 'unknown' ? 'неизвестен' : 'все'}{a.manager ? `; менеджер: ${a.managerOptions?.find(item=>item.id===a.manager)?.name ?? 'не найден'}` : ''}.
        </p>
        <p className="mt-2 text-sm">
          Диалогов: {a.total}; сообщений: {a.messageCount ?? 0}.
        </p>
        {reportType === 'weekly' && (
          <dl className="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3" aria-label="Показатели сводки">
            {[['С контактом', a.contact], ['Передано менеджеру', a.handed], ['Завершено', a.closed]].map(([label, value]) => (
              <div key={label} className="rounded-lg bg-(--color-surface-muted) p-3 text-sm">
                <dt>{label}</dt><dd className="mt-1 text-lg font-semibold">{value ?? 'Нет данных'}</dd>
              </div>
            ))}
          </dl>
        )}
        {reportType === 'weekly' && <section className="mt-4" aria-label="Динамика диалогов"><h4 className="text-sm font-semibold">Диалоги за период</h4>{Object.entries(a.daily ?? {}).length ? <ul className="mt-2 space-y-2">{Object.entries(a.daily ?? {}).slice(-7).map(([date,count])=><li key={date} className="flex items-center gap-3 text-sm"><time dateTime={date} className="w-24 shrink-0">{date}</time><span aria-hidden="true" className="h-2 flex-1 overflow-hidden rounded-full bg-(--color-border)"><span className="block h-full rounded-full bg-(--color-action)" style={{width:`${Math.max(0,Math.min(100,count/Math.max(1,...Object.values(a.daily ?? {}))*100))}%`}}/></span><span>{count}</span></li>)}</ul> : <p className="mt-2 text-sm text-(--color-ink-muted)">Данных за период пока нет.</p>}</section>}
        {reportType === 'topics' && (
          <p className="mt-2 text-sm">
            Сохранённых тем: {Object.keys(a.topics).length}; неизвестных вопросов:{' '}
            {questions.reduce((sum, [, count]) => sum + count, 0)}.
          </p>
        )}
        {reportType === 'dialogs' && (
          <p className="mt-2 text-sm">
            Закрыто без передачи: {closedWithoutHandoff}; длинных без контакта: {longDialogs.length}
            .
          </p>
        )}
        {reportType === 'managers' && (
          <ul className="mt-3 space-y-2 text-sm">
            {(a.managers ?? []).map((m, i) => (
              <li key={i}>
                {m.name}: назначено {m.assigned}, принято {m.taken}, завершено {m.closed}
              </li>
            ))}
          </ul>
        )}
        </div>
        {a.canManage && (
          <form
            id="report-send"
            className="mt-4 print:hidden"
            onSubmit={(event) => {
              event.preventDefault();
              setSendStatus('');
              router.post(
                '/seller/analytics/send',
                {
                  email,
                  report_type: reportType,
                  from: a.from,
                  to: a.to,
                  channel: a.channel ?? '',
                  source: a.source ?? '',
                  manager: a.manager ?? '',
                },
                {
                  preserveScroll: true,
                  onStart: () => setSending(true),
                  onFinish: () => setSending(false),
                  onSuccess: () =>
                    setSendStatus('Тестовый отчёт подготовлен; реальное письмо не отправлено.'),
                  onError: () =>
                    setSendStatus('Не удалось подготовить отчёт. Проверьте адрес и период.'),
                  onNetworkError: () => {
                    setSendStatus('Не удалось связаться с сервером. Повторите попытку.');
                    return false;
                  },
                  onHttpException: () => {
                    setSendStatus('Не удалось подготовить отчёт. Повторите попытку.');
                    return false;
                  },
                },
              );
            }}
          >
            <label className="text-sm">
              Почта получателя
              <input
                type="email"
                value={email}
                onChange={(event) => setEmail(event.target.value)}
                required
                maxLength={255}
                className="mt-1 block min-h-11 rounded-lg border border-(--color-border) bg-(--color-surface) px-3"
              />
            </label>
          </form>
        )}
        {sendStatus && (
          <p role="status" className="mt-2 text-sm">
            {sendStatus}
          </p>
        )}
        <div className="mt-3 flex flex-wrap gap-2 print:hidden">
        {a.canManage && <button type="submit" form="report-send" disabled={sending} className="inline-flex min-h-11 flex-1 cursor-pointer items-center justify-center gap-2 rounded-lg border border-(--color-border) px-4 text-sm font-semibold text-(--color-link) disabled:opacity-50"><Mail size={17} aria-hidden="true" />{sending ? 'Подготавливаем…' : 'Отправить тестовый отчёт'}</button>}
        <a
          href={`/seller/analytics/report.pdf?${new URLSearchParams({ report_type: reportType, from: a.from, to: a.to, channel: a.channel ?? '', source: a.source ?? '', manager: a.manager ?? '' })}`}
          className="inline-flex min-h-11 flex-1 cursor-pointer items-center justify-center gap-2 rounded-lg border border-(--color-border) px-4 text-sm font-semibold text-(--color-link)"
        >
          <FileDown size={17} aria-hidden="true" /> Скачать PDF
        </a>
        <a href={`/seller/analytics/export.csv?${new URLSearchParams({from:a.from,to:a.to,channel:a.channel??'',source:a.source??'',manager:a.manager??''})}`} className="inline-flex min-h-11 flex-1 cursor-pointer items-center justify-center gap-2 rounded-lg border border-(--color-border) px-4 text-sm font-semibold text-(--color-link)"><FileSpreadsheet size={17} aria-hidden="true" /> Скачать CSV</a>
        </div>
        </div>{a.canManage && <aside className="min-w-0 rounded-xl border border-(--color-border) bg-(--color-surface-muted) p-4">
        <form className="space-y-4 print:hidden" onSubmit={event=>{
          event.preventDefault(); setSubStatus('');
          const pending = recipientInput.split(/[,;\n]/).map(value=>value.trim()).filter(Boolean);
          const all = [...recipients, ...pending];
          if (!all.length || all.length > 10 || all.some(value => !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) || new Set(all.map(value => value.toLowerCase())).size !== all.length) {
            setSubStatus('Укажите от 1 до 10 разных корректных адресов.'); return;
          }
          setRecipients(all); setRecipientInput('');
          router.post('/seller/analytics/subscription',{day,recipients:all},{preserveScroll:true,onStart:()=>setSubBusy(true),onFinish:()=>setSubBusy(false),onSuccess:()=>setSubStatus('Расписание сохранено. Реальная отправка пока не подключена.'),onError:()=>setSubStatus('Проверьте список получателей и день недели.'),onNetworkError:()=>{setSubStatus('Нет связи. Повторите попытку.');return false;}});
        }}>
          <h3 className="text-lg font-semibold">Подписка на отчёт</h3><p className="text-sm text-(--color-ink-muted)">Настройте расписание для команды.</p>
          <label className="block text-sm">День недели<select value={day} onChange={event=>setDay(event.target.value)} className="mt-1 block min-h-11 w-full cursor-pointer rounded-lg border border-(--color-border) bg-(--color-surface) px-3">{[['mon','Понедельник'],['tue','Вторник'],['wed','Среда'],['thu','Четверг'],['fri','Пятница'],['sat','Суббота'],['sun','Воскресенье']].map(([value,label])=><option key={value} value={value}>{label}</option>)}</select></label>
          <div><label htmlFor="report-recipients" className="block text-sm">Получатели</label><div className="mt-1 flex min-h-16 flex-wrap items-center gap-2 rounded-lg border border-(--color-border) bg-(--color-surface) p-2">
            {recipients.map(address=><span key={address} className="inline-flex max-w-full items-center gap-1 rounded-full bg-(--color-surface-muted) px-3 py-1 text-sm"><span className="break-all">{address}</span><button type="button" aria-label={`Удалить ${address}`} onClick={()=>{setRecipients(recipients.filter(value=>value!==address));setSubStatus('');}} className="cursor-pointer px-1 text-(--color-link)">×</button></span>)}
            <input id="report-recipients" type="text" inputMode="email" autoComplete="email" value={recipientInput} onChange={event=>setRecipientInput(event.target.value)} onKeyDown={event=>{if(event.key==='Enter'){event.preventDefault();const address=recipientInput.trim();if(!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(address)){setSubStatus('Введите корректный адрес электронной почты.');return;}if(recipients.length>=10 || recipients.some(value=>value.toLowerCase()===address.toLowerCase())){setSubStatus('Адрес уже добавлен или достигнут предел в 10 адресов.');return;}setRecipients([...recipients,address]);setRecipientInput('');setSubStatus('');}}} placeholder="Добавьте email и нажмите Enter" className="min-h-10 min-w-0 flex-1 bg-transparent px-1 text-sm outline-none" />
          </div></div>
          <button disabled={subBusy} className="min-h-11 w-full cursor-pointer rounded-lg bg-(--color-link) px-4 text-sm font-semibold text-white disabled:opacity-50">{subBusy?'Сохраняем…':'Сохранить подписку'}</button>{subStatus&&<p role="status" className="text-sm">{subStatus}</p>}<p className="text-sm text-(--color-ink-muted)">Реальная отправка пока не подключена; доступна тестовая отправка выше.</p>
        </form>
        </aside>}</div>
      </section>
    </>
  );
}
