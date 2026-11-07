import { useForm } from '@inertiajs/react';
import { useState } from 'react';

type Sample = { tag: string; text: string; situation?: string; source?: string; client_type?: string; confidence: number };
type Props = { item: Sample; index: number; bucket: string; version: number; choices: string[]; tagLabels?: Record<string, string>; clientTypes?: string[]; clientTypeLabels?: Record<string, string>; maxWords: number | null };
const field = 'mt-1 block min-h-11 w-full rounded-lg border border-(--color-border) bg-(--color-surface) px-3 text-sm';

export default function SampleCard({ item, index, bucket, version, choices, tagLabels = {}, clientTypes = [], clientTypeLabels = {}, maxWords }: Props) {
 const [networkError, setNetworkError] = useState('');
 const form = useForm({ version, index, bucket, tag: item.tag, text: item.text, situation: item.situation ?? '', source: item.source ?? '', client_type: item.client_type ?? 'all', confidence: item.confidence ?? 0.9, enabled: bucket === 'examples' });
 const words = form.data.text.trim() ? form.data.text.trim().split(/\s+/u).length : 0;
 const originalSource = item.source ?? '';
 const sourceLabel = originalSource.startsWith('dialog #') ? `Из диалога #${originalSource.slice(8)}` : originalSource;
 const tagLabel = (tag: string) => tagLabels[tag] ?? tag;
 const clientTypeLabel = (value: string) => clientTypeLabels[value] ?? value;
 const clientTypeChoices = Array.from(new Set([...clientTypes, form.data.client_type])).filter(Boolean);
 return <li className="rounded-xl border border-(--color-border) bg-(--color-surface) p-6">
  <form onSubmit={e => { e.preventDefault(); form.transform(data => ({ ...data, version })); form.post('/seller/knowledge/fewshot', { preserveScroll: true, onStart: () => setNetworkError(''), onNetworkError: () => { setNetworkError('Нет связи. Повторите сохранение.'); return false; }, onHttpException: () => { setNetworkError('Не удалось сохранить образец. Обновите страницу и повторите.'); return false; } }); }} className="grid gap-x-6 gap-y-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)]">
   <div className="space-y-3 lg:row-span-4">
    <label className="block text-sm">Ситуация<input maxLength={500} value={form.data.situation} onChange={e => form.setData('situation', e.target.value)} className={field}/></label>
    <label className="block text-sm">Ответ<textarea aria-label={`Ответ образца ${index + 1}`} required maxLength={2000} value={form.data.text} onChange={e => form.setData('text', e.target.value)} className={`${field} min-h-24 p-3`}/></label>
    {maxWords !== null && words > maxWords && <p role="status" className="text-sm text-(--color-danger-ink)">Текст длиннее лимита персонажа: слов {words}, лимит {maxWords}.</p>}
   </div>
   <div className="contents">
    <label className="block text-sm">Тег<select aria-label={`Тег образца ${index + 1}`} required value={form.data.tag} onChange={e => form.setData('tag', e.target.value)} className={`${field} cursor-pointer`}>{choices.map(tag => <option key={tag} value={tag}>{tagLabel(tag)}</option>)}</select></label>
    <label className="block text-sm">Источник<select aria-label={`Источник образца ${index + 1}`} value={form.data.source} onChange={e => form.setData('source', e.target.value)} className={`${field} cursor-pointer`}>{originalSource === '' && <option value="">Не указан</option>}<option value="manual">Ручной</option>{originalSource !== '' && originalSource !== 'manual' && <option value={originalSource}>{sourceLabel}</option>}</select></label>
    <label className="block text-sm">Тип клиента<select aria-label={`Тип клиента образца ${index + 1}`} required value={form.data.client_type} onChange={e => form.setData('client_type', e.target.value)} className={`${field} cursor-pointer`}>{clientTypeChoices.map(value => <option key={value} value={value}>{clientTypeLabel(value)}</option>)}</select></label>
    <label className="block text-sm">Насколько строго копировать<div className="mt-2 flex items-center gap-3"><input aria-label={`Строгость образца ${index + 1}`} type="range" min="0" max="1" step="0.01" value={form.data.confidence} onChange={e => form.setData('confidence', Number(e.target.value))} className="min-h-11 w-full cursor-pointer accent-(--color-link) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--color-link)"/><output className="min-w-12 rounded-lg bg-(--color-surface-muted) px-2 py-1 text-center">{form.data.confidence.toFixed(2)}</output></div><span className="flex justify-between text-sm text-(--color-ink-muted)"><span>0</span><span>1</span></span></label>
    <div className="flex items-center gap-3 text-sm"><button type="button" role="switch" aria-label={`Включён образец ${index + 1}`} aria-checked={form.data.enabled} onClick={() => form.setData('enabled', !form.data.enabled)} className={`relative h-7 w-12 cursor-pointer rounded-full transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--color-link) ${form.data.enabled ? 'bg-(--color-link)' : 'bg-(--color-border)'}`}><span className={`absolute top-1 h-5 w-5 rounded-full bg-white transition-transform ${form.data.enabled ? 'left-1 translate-x-5' : 'left-1'}`}/></button><span>{form.data.enabled ? 'Включён' : 'Выключен'}</span></div><details className="relative text-sm" onKeyDown={e => { if (e.key === 'Escape') { e.currentTarget.open = false; e.currentTarget.querySelector('summary')?.focus(); } }}><summary aria-label={`Действия образца ${index + 1}`} className="inline-flex min-h-11 min-w-11 cursor-pointer list-none items-center justify-center rounded-lg border border-(--color-border) text-xl focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--color-link)">⋯</summary><div className="absolute right-0 z-10 min-w-48 rounded-lg border border-(--color-border) bg-(--color-surface) p-2 shadow-lg"><button type="button" className="min-h-11 w-full cursor-pointer rounded px-3 text-left hover:bg-(--color-surface-muted)" onClick={e => { form.reset(); form.clearErrors(); setNetworkError(''); e.currentTarget.closest('details')?.removeAttribute('open'); }}>Сбросить изменения</button></div></details>
    <button aria-label={`Сохранить образец ${index + 1}`} disabled={form.processing} className="min-h-11 cursor-pointer rounded-lg bg-(--color-link) px-4 text-sm text-white disabled:opacity-50">Сохранить образец</button>
    {form.recentlySuccessful && <p role="status" className="text-sm">Образец сохранён.</p>}
    {networkError && <p role="alert" className="text-sm text-(--color-danger-ink)">{networkError}</p>}
    {Object.values(form.errors).map((error, i) => <p role="alert" className="text-sm text-(--color-danger-ink)" key={i}>{error}</p>)}
   </div>
  </form>
 </li>;
}
