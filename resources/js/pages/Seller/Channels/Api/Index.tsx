import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Check, Clipboard, Link2, List, UserRound } from 'lucide-react';
import CabinetLayout from '@/layouts/CabinetLayout';

type Log = { id: string; endpoint: string; source?: 'site' | 'crm' | null; status: string; error?: string | null; created_at: string };
type Props = { mapping: Record<string, string>; logs: Log[]; status?: string | null };
const targets: Record<string, string> = { name: 'Имя', email: 'Почта', phone: 'Телефон', city: 'Город', purpose: 'Цель', experience: 'Опыт', outlet_type: 'Тип точки', current_supplier: 'Текущий поставщик', budget_range: 'Бюджет', timing: 'Срок', messenger: 'Мессенджер' };
const field = 'min-h-11 rounded-lg border border-(--color-border) bg-(--color-surface) px-3 text-sm';
const panel = 'rounded-xl border border-(--color-border) bg-(--color-surface) p-5';

export default function Index({ mapping, logs, status }: Props) {
  const [rows, setRows] = useState(Object.entries(mapping).map(([external, target]) => ({ external, target })));
  const [duplicate, setDuplicate] = useState(false);
  const [copyStatus, setCopyStatus] = useState('');
  const form = useForm<{ mapping: Record<string, string> }>({ mapping });
  const submit = (event: React.FormEvent) => {
    event.preventDefault();
    const keys = rows.map(row => row.external);
    if (new Set(keys).size !== keys.length) { setDuplicate(true); return; }
    setDuplicate(false);
    form.transform(() => ({ mapping: Object.fromEntries(rows.map(row => [row.external, row.target])) }));
    form.post('/seller/channels/api');
  };
  const edit = (index: number, key: 'external' | 'target', value: string) => setRows(rows.map((row, i) => i === index ? { ...row, [key]: value } : row));
  const copy = async (text: string) => {
    try { await navigator.clipboard.writeText(text); setCopyStatus('Скопировано'); }
    catch { setCopyStatus('Не удалось скопировать. Скопируйте текст вручную.'); }
  };
  const sample = JSON.stringify({ fields: { name: 'Имя клиента', email: 'client@example.test' }, source: 'site', action: 'card_only' }, null, 2);

  return <CabinetLayout breadcrumbs={[{ label: 'Продавец', href: '/seller' }, { label: 'Каналы', href: '/seller/channels' }, { label: 'Входящие формы и API' }]}>
    <Head title="Входящие формы и API" />
    <main className="mx-auto max-w-[1440px]" data-private-profile>
      <h1 className="text-3xl font-bold">Входящие формы и API</h1>
      <p className="mt-2 text-sm text-(--color-ink-muted)">Принимайте обращения с сайта и из CRM. Укажите source: site или crm в запросе, чтобы видеть источник в журнале.</p>
      {status && <p role="status" className="mt-4 rounded-lg bg-(--color-success-soft) p-3 text-sm">{status}</p>}
      <div className="mt-6 grid gap-4 lg:grid-cols-2">
        <section className={panel} aria-labelledby="addresses-title">
          <h2 id="addresses-title" className="flex items-center gap-2 text-xl font-bold"><Link2 aria-hidden="true" size={22} />Адреса для POST</h2>
          <p className="mt-2 text-sm text-(--color-ink-muted)">Получайте обращения из форм и внешних систем.</p>
          {['/api/v1/turn', '/api/v1/leads'].map(path => <div key={path} className="mt-3 flex items-center justify-between gap-3 rounded-lg bg-(--color-surface-muted) px-3 py-2"><code className="break-all text-sm">{path}</code><button type="button" aria-label={`Копировать ${path}`} onClick={() => void copy(new URL(path, window.location.origin).href)} className="inline-flex min-h-11 min-w-11 cursor-pointer items-center justify-center rounded-lg border border-(--color-border)"><Clipboard size={18} aria-hidden="true" /></button></div>)}
          <p role="status" className="mt-2 text-sm">{copyStatus}</p>
          <p className="mt-3 text-sm">Используйте заголовки Authorization: Bearer и Idempotency-Key.</p>
          <Link href="/integrations/api" className="mt-2 inline-block cursor-pointer text-sm font-semibold text-(--color-link)">API-ключи и вебхуки →</Link>
        </section>
        <section className={panel} aria-labelledby="sample-title"><h2 id="sample-title" className="text-xl font-bold">Пример запроса</h2><p className="mt-2 text-sm text-(--color-ink-muted)">Пример данных для отправки лида.</p><div className="mt-3 rounded-lg bg-(--color-surface-muted) p-3"><div className="flex items-center justify-between gap-3"><code>POST /api/v1/leads</code><button type="button" onClick={() => void copy(sample)} aria-label="Копировать пример запроса" className="inline-flex min-h-11 min-w-11 cursor-pointer items-center justify-center rounded-lg border border-(--color-border)"><Clipboard size={18} aria-hidden="true" /></button></div><pre className="mt-2 overflow-x-auto text-sm"><code>{sample}</code></pre></div></section>
        <form onSubmit={submit} className={panel} aria-labelledby="mapping-title"><h2 id="mapping-title" className="text-xl font-bold">Сопоставление полей</h2><p className="mt-2 text-sm text-(--color-ink-muted)">Как данные из формы попадают в карточку клиента.</p><div className="mt-4 space-y-3">{rows.map((row, index) => <div key={index} className="flex flex-wrap items-end gap-3 rounded-lg bg-(--color-surface-muted) p-3"><label className="text-sm">Поле формы<input required maxLength={64} pattern="[A-Za-z0-9_-]+" className={`${field} mt-1 block`} value={row.external} onChange={event => edit(index, 'external', event.target.value)} /></label><label className="text-sm">Поле карточки<select className={`${field} mt-1 block cursor-pointer`} value={row.target} onChange={event => edit(index, 'target', event.target.value)}>{Object.entries(targets).map(([key, label]) => <option key={key} value={key}>{label}</option>)}</select></label><button type="button" onClick={() => setRows(rows.filter((_, i) => i !== index))} className="min-h-11 cursor-pointer px-2 text-sm text-(--color-link)">Удалить</button></div>)}</div><button type="button" onClick={() => setRows([...rows, { external: '', target: 'name' }])} className={`${field} mt-4 cursor-pointer text-(--color-link)`}>Добавить поле</button>{(duplicate || form.errors.mapping) && <p role="alert" className="mt-3 text-sm text-(--color-danger-ink)">{form.errors.mapping || 'Имена полей формы не должны повторяться.'}</p>}<button disabled={form.processing} className="mt-4 ml-2 min-h-11 cursor-pointer rounded-lg bg-(--color-link) px-5 text-sm font-semibold text-white disabled:opacity-50">Сохранить</button></form>
        <section className={panel} aria-labelledby="new-lead-title"><h2 id="new-lead-title" className="flex items-center gap-2 text-xl font-bold"><UserRound size={22} aria-hidden="true" />Новый лид</h2><p className="mt-2 text-sm text-(--color-ink-muted)">Что делать с новым обращением</p><fieldset className="mt-4 space-y-4"><legend className="sr-only">Действие с новым лидом</legend><label className="flex items-start gap-3 text-sm text-(--color-ink-muted)"><input type="radio" name="new-lead-action" value="open" disabled className="mt-1" /><span>Написать первым в указанный канал<br /><span>Автоматическое начало диалога пока недоступно.</span></span></label><label className="flex items-start gap-3 text-sm"><input type="radio" name="new-lead-action" value="card_only" checked readOnly className="mt-1" /><span>Только завести карточку<br /><span className="text-(--color-ink-muted)">Создать клиента и сохранить данные без отправки сообщения.</span></span></label></fieldset></section>
      </div>
      <section className={`${panel} mt-4`} aria-labelledby="recent-title"><h2 id="recent-title" className="flex items-center gap-2 text-xl font-bold"><List size={22} aria-hidden="true" />Последние входящие</h2><p className="mt-2 text-sm text-(--color-ink-muted)">Последние 20 запросов</p><a href="/seller/channels/api/failures" className="mt-3 inline-block cursor-pointer text-sm font-semibold text-(--color-link)">Журнал ошибок API →</a>{logs.length === 0 ? <p role="status" className="mt-3 text-sm">Входящих запросов пока нет.</p> : <div className="mt-3 overflow-x-auto"><table className="w-full min-w-[560px] text-left text-sm"><thead><tr className="bg-(--color-surface-muted)"><th scope="col" className="p-2">Время</th><th scope="col" className="p-2">Источник</th><th scope="col" className="p-2">Статус</th><th scope="col" className="p-2">Ошибка</th></tr></thead><tbody>{logs.map(log => <tr key={log.id} className="border-b border-(--color-border)"><td className="p-2"><time>{new Date(log.created_at).toLocaleString('ru-RU')}</time></td><td className="p-2">{log.source === 'site' ? 'Сайт' : log.source === 'crm' ? 'CRM' : 'API'} · {log.endpoint === 'leads' ? '/api/v1/leads' : '/api/v1/turn'}</td><td className="p-2">{log.status === 'error' ? 'Ошибка' : <span className="inline-flex items-center gap-1"><Check size={14} aria-hidden="true" />Успешно</span>}</td><td className="p-2">{log.error || '—'}</td></tr>)}</tbody></table></div>}</section>
    </main>
  </CabinetLayout>;
}
