import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import CabinetLayout from '@/layouts/CabinetLayout';

type Candidate = {key:string;title:string;question_id:string;why:string};
type Review = {report_id:string;candidates:Candidate[]};
type Report = { id: string; revision: number; created_at: string };
type Publication = { id: string; report_id: string; expires_at: string | null; revoked_at: string | null; created_at: string };
const button = 'min-h-11 cursor-pointer rounded-lg border border-(--color-border) px-4 text-sm font-semibold disabled:cursor-not-allowed disabled:opacity-50';

export default function Index({ reports, publications, ttlDays, canPublish, reportBrandRevision, createdUrl, status, review = null }: { review?: Review|null; reports: Report[]; publications: Publication[]; ttlDays: number[]; canPublish: boolean; reportBrandRevision: string; createdUrl?: string | null; status?: string | null }) {
  const { brand } = usePage<{ brand: { id: string } | null }>().props;
  const headers = { 'X-Brand-Id': brand?.id ?? '', 'X-Brand-Revision': reportBrandRevision };
  const form = useForm<{report_id:string;ttl_days:number;confirmed_actions:string[]}>({ report_id: reports[0]?.id ?? '', ttl_days: ttlDays[0] ?? 1, confirmed_actions: [] });
  const [loadingReview,setLoadingReview]=useState(false);
  const [reviewError,setReviewError]=useState('');
  const [retry,setRetry]=useState(0);
  useEffect(()=>{
    if (!canPublish || !form.data.report_id) return;
    setReviewError('');
    router.get('/vision/publications',{review_report:form.data.report_id},{only:['review'],preserveScroll:true,preserveState:true,replace:true,onStart:()=>setLoadingReview(true),onFinish:()=>setLoadingReview(false),onError:()=>setReviewError('Не удалось загрузить предложения.'),onNetworkError:()=>{setReviewError('Не удалось загрузить предложения.');return false;},onHttpException:()=>{setReviewError('Не удалось загрузить предложения.');return false;}});
  },[canPublish,form.data.report_id,retry]);
  const candidates = review?.report_id === form.data.report_id ? review.candidates : [];
  const reviewReady = review?.report_id === form.data.report_id && !loadingReview && !reviewError;
  const [revoking, setRevoking] = useState('');
  const [error, setError] = useState('');
  const revoke = (id: string) => {
    if (!window.confirm('Отозвать ссылку? Открыть её после этого будет нельзя.')) return;
    setError(''); setRevoking(id);
    router.delete(`/vision/publications/${id}`, {
      headers,
      onFinish: () => setRevoking(''),
      onError: (errors) => setError(Object.values(errors)[0] ?? 'Не удалось отозвать ссылку.'),
      onNetworkError: () => { setError('Нет связи. Проверьте статус ссылки перед повтором.'); return false; },
    });
  };
  return <CabinetLayout breadcrumbs={[{ label: 'Главная', href: '/dashboard' }, { label: 'Видение', href: '/vision' }, { label: 'Публичные отчёты' }]}>
    <Head title="Публичные отчёты" />
    <main className="mx-auto max-w-4xl pb-12">
      <Link href="/vision/report" className="inline-flex min-h-11 cursor-pointer items-center text-sm font-semibold text-(--color-link)">← К внутренним отчётам</Link>
      <h1 className="mt-3 text-3xl font-bold">Публичные отчёты</h1>
      <p className="mt-2 text-sm text-(--color-ink-muted)">Создайте ссылку на безопасную сводку. Её можно отозвать в любой момент.</p>
      {status && <p role="status" className="mt-4 rounded-lg bg-(--color-success-soft) p-3 text-sm">{status}</p>}
      {error && <p role="alert" className="mt-4 rounded-lg bg-(--color-danger-soft) p-3 text-sm">{error}</p>}
      {createdUrl && <div className="mt-4 rounded-xl border border-(--color-border) bg-(--color-surface) p-5"><h2 className="text-lg font-bold">Новая ссылка</h2><p className="mt-2 text-sm">Сохраните её сейчас. После ухода со страницы токен нельзя получить повторно.</p><a href={createdUrl} target="_blank" rel="noreferrer" className="mt-3 block break-all text-sm text-(--color-link) underline">{createdUrl}</a></div>}
      <section className="mt-6 rounded-2xl border border-(--color-border) bg-(--color-surface) p-5 shadow-(--shadow-card)"><h2 className="text-xl font-bold">Создать ссылку</h2>
        {reports.length === 0 ? <p className="mt-3 text-sm">Подтверждённых отчётов пока нет. Сначала подготовьте внутренний отчёт.</p> : <form className="mt-4 space-y-4" onSubmit={(event) => { event.preventDefault(); form.post('/vision/publications', { headers }); }}>
          <label className="block text-sm font-semibold">Отчёт<select disabled={!canPublish} value={form.data.report_id} onChange={(event) => { const reportId = event.target.value; form.setData(data=>({...data,report_id:reportId,confirmed_actions:[]})); }} className="mt-2 min-h-11 w-full cursor-pointer rounded-lg border border-(--color-border) bg-(--color-surface) px-3 text-sm">{reports.map((report) => <option key={report.id} value={report.id}>Версия {report.revision} · {new Date(report.created_at).toLocaleDateString('ru-RU')}</option>)}</select></label>
          {form.errors.report_id && <p role="alert" className="text-sm text-(--color-danger-ink)">{form.errors.report_id}</p>}
          {canPublish && <section aria-label="Предложения для публикации" className="rounded-xl bg-(--color-surface-muted) p-4 text-sm"><h3 className="font-semibold">Выберите предложения</h3><p className="mt-2">Можно выбрать до трёх. Без выбора действия не попадут в публичную ссылку.</p>{loadingReview&&<p role="status" className="mt-2">Загрузка предложений…</p>}{reviewError&&<div role="alert" className="mt-2">{reviewError} <button type="button" className="cursor-pointer text-(--color-link)" onClick={()=>setRetry(value=>value+1)}>Повторить</button></div>}{reviewReady && (candidates.length ? <div className="mt-3 space-y-2">{candidates.map(item=><label key={item.key} className="flex min-h-11 cursor-pointer gap-3 rounded-lg bg-(--color-surface) p-3"><input type="checkbox" checked={form.data.confirmed_actions.includes(item.key)} disabled={form.processing || (!form.data.confirmed_actions.includes(item.key) && form.data.confirmed_actions.length>=3)} onChange={()=>form.setData('confirmed_actions',form.data.confirmed_actions.includes(item.key)?form.data.confirmed_actions.filter(key=>key!==item.key):[...form.data.confirmed_actions,item.key])}/><span><strong>{item.title}</strong><span className="block text-(--color-ink-muted)">Вопрос {item.question_id} · {item.why}</span></span></label>)}</div> : <p className="mt-2">Проверенных предложений для публикации нет.</p>)}{form.errors.confirmed_actions&&<p role="alert" className="mt-2 text-(--color-danger-ink)">{form.errors.confirmed_actions}</p>}</section>}
          <label className="block text-sm font-semibold">Срок действия<select disabled={!canPublish} value={form.data.ttl_days} onChange={(event) => form.setData('ttl_days', Number(event.target.value))} className="mt-2 min-h-11 w-full cursor-pointer rounded-lg border border-(--color-border) bg-(--color-surface) px-3 text-sm">{ttlDays.map((days) => <option key={days} value={days}>{days} {days === 1 ? 'день' : 'дней'}</option>)}</select></label>
          {form.errors.ttl_days && <p role="alert" className="text-sm text-(--color-danger-ink)">{form.errors.ttl_days}</p>}
          {canPublish && <button disabled={form.processing || !reviewReady} className={`${button} bg-(--color-link) text-white`}>{form.processing ? 'Создаём…' : 'Создать ссылку'}</button>}
        </form>}
      </section>
      <section className="mt-6 rounded-2xl border border-(--color-border) bg-(--color-surface) p-5 shadow-(--shadow-card)"><h2 className="text-xl font-bold">Созданные ссылки</h2>
        {publications.length === 0 ? <p className="mt-3 text-sm">Публикаций ещё нет.</p> : <ul className="mt-4 space-y-3">{publications.map((item) => <li key={item.id} className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-(--color-border) p-4 text-sm"><div><p className="font-semibold">Отчёт {reports.find((report) => report.id === item.report_id)?.revision ?? '—'}</p><p className="mt-1 text-(--color-ink-muted)">{item.revoked_at ? 'Отозвана' : item.expires_at && new Date(item.expires_at) <= new Date() ? 'Срок истёк' : `До ${item.expires_at ? new Date(item.expires_at).toLocaleDateString('ru-RU') : 'отзыва'}`}</p></div>{canPublish && !item.revoked_at && <button type="button" disabled={revoking === item.id} onClick={() => revoke(item.id)} className={button}>Отозвать</button>}</li>)}</ul>}
      </section>
    </main>
  </CabinetLayout>;
}
