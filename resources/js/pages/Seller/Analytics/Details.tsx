import { Link, router } from '@inertiajs/react';
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
  messageCount?: number;
  botCompleted?: number;
  dialogs?: Dialog[];
  hours?: Record<string, number>;
  unknownQuestions?: Record<string, number>;
  unknownDialogs?: number;
  triggerStats?: Record<string, { dialogs: number; with_contact: number }>;
  triggers?: Record<string, number>;
  topics: Record<string, number>;
  channel?: string;
  source?: string;
  manager?: string;
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
  const [reportType, setReportType] = useState('weekly');
  const [email, setEmail] = useState('');
  const [sending, setSending] = useState(false);
  const [day, setDay] = useState(a.subscription?.day ?? 'mon');
  const [recipients, setRecipients] = useState(a.subscription?.recipients.join('\n') ?? '');
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
        <label className="mt-3 block text-sm print:hidden">
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
        <h3 className="mt-4 font-semibold">
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
          {a.source === 'unknown' ? 'неизвестен' : 'все'}.
        </p>
        <p className="mt-2 text-sm">
          Диалогов: {a.total}; сообщений: {a.messageCount ?? 0}.
        </p>
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
        {a.canManage && (
          <form
            className="mt-4 flex flex-wrap items-end gap-2 print:hidden"
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
            <button
              type="submit"
              disabled={sending}
              className="min-h-11 cursor-pointer rounded-lg border border-(--color-border) px-3 text-sm disabled:opacity-50"
            >
              {sending ? 'Подготавливаем…' : 'Отправить тестовый отчёт'}
            </button>
          </form>
        )}
        {sendStatus && (
          <p role="status" className="mt-2 text-sm">
            {sendStatus}
          </p>
        )}
        {a.canManage && <form className="mt-5 space-y-3 print:hidden" onSubmit={event=>{event.preventDefault();setSubStatus('');router.post('/seller/analytics/subscription',{day,recipients:recipients.split(/[,\n]/).map(value=>value.trim()).filter(Boolean)},{preserveScroll:true,onStart:()=>setSubBusy(true),onFinish:()=>setSubBusy(false),onSuccess:()=>setSubStatus('Расписание сохранено. Реальная отправка пока не подключена.'),onError:()=>setSubStatus('Проверьте список получателей и день недели.'),onNetworkError:()=>{setSubStatus('Нет связи. Повторите попытку.');return false;}});}}><h3 className="text-lg font-semibold">Подписка на отчёт</h3><label className="block text-sm">День недели<select value={day} onChange={event=>setDay(event.target.value)} className="mt-1 block min-h-11 cursor-pointer rounded-lg border border-(--color-border) bg-(--color-surface) px-3">{[['mon','Понедельник'],['tue','Вторник'],['wed','Среда'],['thu','Четверг'],['fri','Пятница'],['sat','Суббота'],['sun','Воскресенье']].map(([value,label])=><option key={value} value={value}>{label}</option>)}</select></label><label className="block text-sm">Получатели, по одному адресу в строке<textarea required value={recipients} onChange={event=>setRecipients(event.target.value)} className="mt-1 block min-h-24 w-full max-w-lg rounded-lg border border-(--color-border) bg-(--color-surface) p-3"/></label><button disabled={subBusy} className="min-h-11 cursor-pointer rounded-lg border border-(--color-border) px-4 text-sm disabled:opacity-50">{subBusy?'Сохраняем…':'Сохранить подписку'}</button>{subStatus&&<p role="status" className="text-sm">{subStatus}</p>}<p className="text-sm text-(--color-ink-muted)">Реальная отправка пока не подключена; доступна тестовая отправка выше.</p></form>}
        <p className="mt-2 text-sm text-(--color-ink-muted)">
          Для PDF выберите «Сохранить как PDF» в окне печати. В отчёт войдут данные и фильтры на
          этой странице.
        </p>
        <button
          type="button"
          onClick={() => window.print()}
          className="mt-3 min-h-11 cursor-pointer rounded-lg bg-(--color-link) px-4 text-sm font-semibold text-white print:hidden"
        >
          Печать / PDF
        </button>
      </section>
    </>
  );
}
