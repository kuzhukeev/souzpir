import { useForm } from '@inertiajs/react';
import { useState } from 'react';

type Sample = { tag: string; text: string; situation?: string; source?: string; client_type?: string; confidence: number };
type Props = { item: Sample; index: number; bucket: string; version: number; choices: string[]; maxWords: number | null };
const field = 'mt-1 block min-h-11 w-full rounded-lg border border-(--color-border) bg-(--color-surface) px-3 text-sm';

export default function SampleCard({ item, index, bucket, version, choices, maxWords }: Props) {
 const [networkError, setNetworkError] = useState('');
 const form = useForm({ version, index, bucket, tag: item.tag, text: item.text, situation: item.situation ?? '', source: item.source ?? '', client_type: item.client_type ?? 'all', confidence: item.confidence ?? 0.9, enabled: bucket === 'examples' });
 const words = form.data.text.trim() ? form.data.text.trim().split(/\s+/u).length : 0;
 return <li className="rounded-xl border border-(--color-border) bg-(--color-surface) p-6">
  <form onSubmit={e => { e.preventDefault(); form.transform(data => ({ ...data, version })); form.post('/seller/knowledge/fewshot', { preserveScroll: true, onStart: () => setNetworkError(''), onNetworkError: () => { setNetworkError('Нет связи. Повторите сохранение.'); return false; } }); }} className="grid gap-x-6 gap-y-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)]">
   <div className="space-y-3 lg:row-span-4">
    <label className="block text-sm">Ситуация<input maxLength={500} value={form.data.situation} onChange={e => form.setData('situation', e.target.value)} className={field}/></label>
    <label className="block text-sm">Ответ<textarea aria-label={`Ответ образца ${index + 1}`} required maxLength={2000} value={form.data.text} onChange={e => form.setData('text', e.target.value)} className={`${field} min-h-24 p-3`}/></label>
    {maxWords !== null && words > maxWords && <p role="status" className="text-sm text-(--color-danger-ink)">Текст длиннее лимита персонажа: слов {words}, лимит {maxWords}.</p>}
   </div>
   <div className="contents">
    <label className="block text-sm">Тег<select aria-label={`Тег образца ${index + 1}`} required value={form.data.tag} onChange={e => form.setData('tag', e.target.value)} className={`${field} cursor-pointer`}>{choices.map(tag => <option key={tag} value={tag}>{tag}</option>)}</select></label>
    <label className="block text-sm">Источник<input maxLength={200} value={form.data.source} onChange={e => form.setData('source', e.target.value)} className={field}/></label>
    <label className="block text-sm">Тип клиента<input required maxLength={64} value={form.data.client_type} onChange={e => form.setData('client_type', e.target.value)} className={field}/></label>
    <label className="block text-sm">Насколько строго копировать<input type="range" min="0" max="1" step="0.01" value={form.data.confidence} onChange={e => form.setData('confidence', Number(e.target.value))} className="mt-2 block w-full cursor-pointer accent-(--color-link)"/><output>{form.data.confidence.toFixed(2)}</output></label>
    <label className="flex cursor-pointer gap-2 text-sm"><input type="checkbox" checked={form.data.enabled} onChange={e => form.setData('enabled', e.target.checked)}/>Включён</label>
    <button aria-label={`Сохранить образец ${index + 1}`} disabled={form.processing} className="min-h-11 cursor-pointer rounded-lg bg-(--color-link) px-4 text-sm text-white disabled:opacity-50">Сохранить образец</button>
    {form.recentlySuccessful && <p role="status" className="text-sm">Образец сохранён.</p>}
    {networkError && <p role="alert" className="text-sm text-(--color-danger-ink)">{networkError}</p>}
    {Object.values(form.errors).map((error, i) => <p role="alert" className="text-sm text-(--color-danger-ink)" key={i}>{error}</p>)}
   </div>
  </form>
 </li>;
}
