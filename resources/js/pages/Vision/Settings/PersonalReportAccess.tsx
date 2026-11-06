import { router } from '@inertiajs/react';
import { useState } from 'react';

type Report = { id: string; revision: number; created_at: string };
type Access = { id: string; name: string; email: string; report_id: string; expires_at: string; expired: boolean; revoked_at: string | null; opens: string[] };
type Props = { reports: Report[]; access: Access[]; createdUrl: string | null; brandId: string; revision: string };
const field = 'min-h-11 rounded-lg border border-(--color-border) bg-(--color-surface) px-3 text-sm';

export default function PersonalReportAccess({ reports, access, createdUrl, brandId, revision }: Props) {
 const [name, setName] = useState('');
 const [email, setEmail] = useState('');
 const [report, setReport] = useState(reports[0]?.id ?? '');
 const [busy, setBusy] = useState(false);
 const [error, setError] = useState('');
 const [copied, setCopied] = useState(false);
 const headers = { 'X-Brand-Id': brandId, 'X-Brand-Revision': revision };
 const fail = () => { setError('Не удалось выполнить действие. Проверьте данные и повторите.'); return false; };
 return <section className="mt-6 rounded-xl border border-(--color-border) bg-(--color-surface) p-5">
  <h3 className="text-xl font-bold">Персональный доступ к отчёту</h3>
  <p className="mt-2 rounded-lg bg-(--color-info-soft) p-3 text-sm text-(--color-info-ink)">Внешние получатели видят только публичную версию. Ссылка действует 30 дней; передайте её самостоятельно.</p>
  {error && <p role="alert" className="mt-3 rounded-lg bg-(--color-danger-soft) p-3 text-sm text-(--color-danger-ink)">{error}</p>}
  {createdUrl && <div className="mt-4 rounded-lg bg-(--color-surface-muted) p-3 text-sm"><label className="block">Скопируйте ссылку сейчас<input aria-label="Персональная ссылка" className={`${field} mt-1 block w-full`} readOnly value={createdUrl}/></label><button type="button" className="mt-2 min-h-11 cursor-pointer text-(--color-link)" onClick={() => { void navigator.clipboard.writeText(createdUrl).then(() => setCopied(true)).catch(() => setError('Не удалось скопировать ссылку. Выделите её и скопируйте вручную.')); }}>Копировать</button>{copied && <span role="status" className="ml-3">Скопировано</span>}</div>}
  {reports.length === 0 ? <p className="mt-4 rounded-lg bg-(--color-surface-muted) p-4 text-sm">Нет отчётов, доступных для публикации.</p> : <form className="mt-4 grid gap-3 sm:grid-cols-2" onSubmit={e => { e.preventDefault(); setError(''); router.post('/vision/settings/personal-report-access', { name, email, report_id: report }, { headers, preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: () => { setName(''); setEmail(''); setCopied(false); }, onError: fail, onNetworkError: fail, onHttpException: fail }); }}>
   <label className="text-sm">Имя получателя<input required maxLength={120} className={`${field} mt-1 block w-full`} value={name} onChange={e => setName(e.target.value)}/></label>
   <label className="text-sm">Электронная почта<input required type="email" maxLength={254} className={`${field} mt-1 block w-full`} value={email} onChange={e => setEmail(e.target.value)}/></label>
   <label className="text-sm">Отчёт<select required className={`${field} mt-1 block w-full cursor-pointer`} value={report} onChange={e => setReport(e.target.value)}>{reports.map(item => <option key={item.id} value={item.id}>Версия {item.revision} · {new Date(item.created_at).toLocaleDateString('ru-RU')}</option>)}</select></label>
   <button disabled={busy || !report} className={`${field} self-end cursor-pointer font-semibold text-(--color-link) disabled:opacity-50`}>Создать ссылку</button>
  </form>}
  <h4 className="mt-6 font-semibold">Выданные ссылки и открытия</h4>
  {access.length === 0 ? <p className="mt-2 rounded-lg bg-(--color-surface-muted) p-4 text-sm text-(--color-ink-muted)">Ссылок пока нет.</p> : <ul className="mt-3 space-y-3">{access.map(item => <li key={item.id} className="rounded-lg bg-(--color-surface-muted) p-3 text-sm"><p className="break-words font-semibold">{item.name} · {item.email}</p><p className="mt-1 break-all">Отчёт: {item.report_id}</p><p className={`mt-2 inline-block rounded-full px-3 py-1 ${item.revoked_at || item.expired ? 'bg-(--color-danger-soft) text-(--color-danger-ink)' : 'bg-(--color-success-soft) text-(--color-success-ink)'}`}>{item.revoked_at ? 'Отозвана' : item.expired ? 'Срок истёк' : `Действует до ${new Date(item.expires_at).toLocaleString('ru-RU')}`}</p><details className="mt-2"><summary className="cursor-pointer">Открытия: {item.opens.length}</summary>{item.opens.length ? <ul className="mt-2">{item.opens.map((at, i) => <li key={i}>{new Date(at).toLocaleString('ru-RU')}</li>)}</ul> : <p className="mt-2">Открытий пока нет.</p>}</details>{!item.revoked_at && <button type="button" disabled={busy} className="mt-2 min-h-11 cursor-pointer text-(--color-danger-ink) disabled:opacity-50" onClick={() => { setError(''); router.delete(`/vision/settings/personal-report-access/${item.id}`, { headers, preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onError: fail, onNetworkError: fail, onHttpException: fail }); }}>Отозвать</button>}</li>)}</ul>}
 </section>;
}
