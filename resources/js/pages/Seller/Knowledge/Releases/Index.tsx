import { Head, useForm } from '@inertiajs/react';
import CabinetLayout from '@/layouts/CabinetLayout';

type Release = { id: string; status: string; version: number; published_at: string | null; updated_at: string };
type Task = { id: string; payload: string; created_at: string };
type Review = { id: string; note: string | null; better_text: string | null };
type Evaluation = { id: string; issues: string; comment: string | null };
type Props = { releases: Release[]; draft: Release | null; activeId: string | null; counts: Record<string, number>; tasks: Task[]; reviews: Review[]; evaluations: Evaluation[]; canWrite: boolean; status: string | null };
const card = 'rounded-xl border border-(--color-border) bg-(--color-surface) p-5';
const button = 'min-h-11 cursor-pointer rounded-lg bg-(--color-link) px-4 text-sm font-semibold text-white disabled:opacity-50';
const date = (value: string | null) => value ? new Date(value).toLocaleString('ru-RU') : '—';
function display(value: string | object | null): string {
 if (!value) return 'Нет описания';
 try { const decoded = typeof value === 'string' ? JSON.parse(value) : value; if (Array.isArray(decoded)) return decoded.map((item: {explanation?: string; fix?: string}) => [item.explanation, item.fix].filter(Boolean).join(' — ')).filter(Boolean).join('\n') || 'Без описания'; return decoded.question || decoded.text || 'Без описания'; } catch { return String(value); }
}
export default function Index({ releases, draft, activeId, counts, tasks, reviews, evaluations, canWrite, status }: Props) {
 const form = useForm<{version?: number}>({});
 const post = (url: string, version?: number) => { form.transform(() => version ? {version} : {}); form.post(url, {preserveScroll:true}); };
 return <CabinetLayout breadcrumbs={[{label:'Продавец',href:'/seller'},{label:'База знаний'}]}><Head title="База знаний"/><main className="mx-auto max-w-[1500px]" data-private-profile>
 <h1 className="text-3xl font-bold">База знаний</h1><p className="mt-2 text-sm text-(--color-ink-muted)">Опубликованные знания продавца и предложения по улучшению</p>
 {status && <p role="status" className="mt-4 rounded-lg bg-(--color-success-soft) p-3 text-sm">{status}</p>}
 <div className="mt-6 grid gap-4 md:grid-cols-4">{[['facts','Факты'],['intents','Намерения'],['triggers','Триггеры'],['closed_topics','Закрытые темы']].map(([key,label])=><div key={key} className={card}><p className="text-sm">{label}</p><p className="mt-2 text-2xl font-bold">{counts[key] ?? '—'}</p></div>)}</div>
 <section className={`${card} mt-4`}><h2 className="text-xl font-bold">Черновик и публикация</h2><p className="mt-2 text-sm">{draft ? `Черновик · версия ${draft.version} · изменён ${date(draft.updated_at)}` : 'Черновика пока нет.'}</p><p className="mt-2 text-sm text-(--color-ink-muted)">{activeId ? 'Опубликованная версия доступна продавцу.' : 'Знания ещё не опубликованы.'}</p>
 {canWrite && <div className="mt-4 flex flex-wrap gap-3">{draft ? <button type="button" disabled={form.processing} onClick={()=>post(`/seller/knowledge/${draft.id}/publish`,draft.version)} className={button}>{form.processing?'Публикуем…':'Опубликовать'}</button> : <button type="button" disabled={form.processing} onClick={()=>post('/seller/knowledge/draft')} className={button}>Создать черновик</button>}</div>}
 <p className="mt-3 text-sm text-(--color-ink-muted)">Редактирование условий и образцов будет подключено отдельно.</p></section>
 <section className={`${card} mt-4`}><h2 className="text-xl font-bold">История публикаций</h2>{releases.filter(r=>r.status==='published').length===0?<p className="mt-3 text-sm">Публикаций пока нет.</p>:<ul className="mt-3 space-y-3">{releases.filter(r=>r.status==='published').map(r=><li key={r.id} className="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-(--color-surface-muted) p-3 text-sm"><span>{date(r.published_at)} {r.id===activeId && '· Активная версия'}</span>{canWrite&&r.id!==activeId&&<button type="button" className="min-h-11 cursor-pointer rounded-lg border border-(--color-border) px-4 font-semibold text-(--color-link)" disabled={form.processing} onClick={()=>post(`/seller/knowledge/${r.id}/rollback`)}>Восстановить</button>}</li>)}</ul>}</section>
 <section className={`${card} mt-4`}><h2 className="text-xl font-bold">Входящие</h2><p className="mt-2 text-sm text-(--color-ink-muted)">Неизвестные вопросы, неверные факты и замечания проверок. Добавление в черновик появится отдельно.</p>
 {tasks.length+reviews.length+evaluations.length===0?<p className="mt-3 text-sm">Новых предложений нет.</p>:<ul className="mt-3 space-y-3">{tasks.map(t=><li key={t.id} className="rounded-lg bg-(--color-surface-muted) p-3 text-sm"><strong>Неизвестный вопрос</strong><pre className="mt-2 whitespace-pre-wrap break-words font-sans">{display(t.payload)}</pre></li>)}{reviews.map(r=><li key={r.id} className="rounded-lg bg-(--color-surface-muted) p-3 text-sm"><strong>Неверный факт</strong><p className="mt-2 whitespace-pre-wrap">{r.note || r.better_text || 'Без комментария'}</p></li>)}{evaluations.map(e=><li key={e.id} className="rounded-lg bg-(--color-surface-muted) p-3 text-sm"><strong>Замечания проверки</strong><p className="mt-2 whitespace-pre-wrap">{e.comment}</p><pre className="mt-2 whitespace-pre-wrap break-words font-sans">{display(e.issues)}</pre></li>)}</ul>}</section>
 </main></CabinetLayout>;
}

