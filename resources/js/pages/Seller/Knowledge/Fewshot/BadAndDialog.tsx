import { router, useForm } from '@inertiajs/react';
import * as DialogPrimitive from '@radix-ui/react-dialog';
import { useState } from 'react';

type Bad = { text: string; why?: string };
type Dialog = { id: string; name: string };
type Message = { id: string; text: string };

const field = 'mt-1 block min-h-11 w-full rounded-lg border border-(--color-border) bg-(--color-surface) px-3 text-sm';
const button = 'min-h-11 cursor-pointer rounded-lg border border-(--color-border) px-4 text-sm font-semibold disabled:opacity-50';

export default function BadAndDialog({ version, badExamples, maxWords = null, tags, open, onOpenChange }: { version: number; badExamples: Bad[]; maxWords?: number | null; tags: string[]; open: boolean; onOpenChange: (value: boolean) => void }) {
  const bad = useForm({ version, index: null as number | null, text: '', why: '' });
  const sample = useForm({ version, dialog: '', message: '', tag: '', situation: '', confidence: 0.9 });
  const [search, setSearch] = useState('');
  const [dialogs, setDialogs] = useState<Dialog[]>([]);
  const [messages, setMessages] = useState<Message[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const selectedText = messages.find(message => message.id === sample.data.message)?.text.trim() ?? '';
  const selectedWords = selectedText ? selectedText.split(/\s+/u).length : 0;

  async function findDialogs() {
    setLoading(true); setError(''); setDialogs([]); setMessages([]);
    sample.setData(current => ({ ...current, dialog: '', message: '' }));
    try {
      const response = await fetch(`/seller/knowledge/fewshot/dialogs?${new URLSearchParams({ search })}`, { headers: { Accept: 'application/json' } });
      if (!response.ok) throw new Error('Не удалось загрузить диалоги.');
      const data = await response.json() as { dialogs: Dialog[] };
      setDialogs(data.dialogs);
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Ошибка загрузки.'); }
    finally { setLoading(false); }
  }

  async function chooseDialog(id: string) {
    sample.setData(current => ({ ...current, dialog: id, message: '' }));
    setMessages([]); setLoading(true); setError('');
    try {
      const response = await fetch(`/seller/knowledge/fewshot/dialogs/${id}`, { headers: { Accept: 'application/json' } });
      if (!response.ok) throw new Error('Не удалось загрузить реплики.');
      const data = await response.json() as { messages: Message[] };
      setMessages(data.messages);
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Ошибка загрузки.'); }
    finally { setLoading(false); }
  }

  function deleteBad(index: number) {
    router.delete('/seller/knowledge/fewshot/bad', { data: { version, index }, preserveScroll: true });
  }

  return <div className="mt-5 space-y-5">
    <section className="rounded-xl border border-(--color-border) bg-(--color-surface) p-5"><h2 className="text-xl font-bold">Плохие образцы</h2><p className="mt-2 text-sm text-(--color-ink-muted)">Фразы, которые продавец не должен повторять.</p>
      {badExamples.length === 0 ? <p className="mt-4 text-sm">Плохих образцов пока нет.</p> : <ul className="mt-4 space-y-3">{badExamples.map((item, index) => <li className="rounded-lg bg-(--color-surface-muted) p-3 text-sm" key={index}><p className="font-semibold">{item.text}</p><p className="mt-1">Почему не подходит: {item.why}</p><div className="mt-2 flex flex-wrap gap-3"><button type="button" className="cursor-pointer text-(--color-link)" onClick={() => bad.setData({ version, index, text: item.text, why: item.why ?? '' })}>Изменить</button><button type="button" className="cursor-pointer text-(--color-danger-ink)" onClick={() => deleteBad(index)}>Удалить</button></div></li>)}</ul>}
      <form className="mt-5 space-y-3" onSubmit={event => { event.preventDefault(); bad.transform(data => ({ ...data, version })); bad.post('/seller/knowledge/fewshot/bad', { preserveScroll: true, onSuccess: () => bad.setData({ version: version + 1, index: null, text: '', why: '' }) }); }}><h3 className="text-base font-semibold">{bad.data.index === null ? 'Добавить плохой образец' : 'Изменить плохой образец'}</h3><label className="block text-sm">Неподходящий ответ<textarea required maxLength={2000} value={bad.data.text} onChange={event => bad.setData('text', event.target.value)} className={`${field} min-h-24 p-3`} /></label><label className="block text-sm">Почему не подходит<input required maxLength={500} value={bad.data.why} onChange={event => bad.setData('why', event.target.value)} className={field} /></label><button disabled={bad.processing} className={`${button} bg-(--color-link) text-white`}>Сохранить плохой образец</button>{Object.values(bad.errors).map((message, index) => <p role="alert" className="text-sm text-(--color-danger-ink)" key={index}>{message}</p>)}</form>
    </section>
    <section className="rounded-xl border border-(--color-border) bg-(--color-surface) p-5"><DialogPrimitive.Root open={open} onOpenChange={onOpenChange}><div className="flex flex-wrap items-center justify-between gap-3"><div><h2 className="text-xl font-bold">Из диалога</h2><p className="mt-1 text-sm text-(--color-ink-muted)">Выберите реплику менеджера из доступного вам диалога.</p></div><DialogPrimitive.Trigger asChild><button type="button" className={button}>Добавить из диалога</button></DialogPrimitive.Trigger></div>
      <DialogPrimitive.Portal><DialogPrimitive.Overlay className="fixed inset-0 z-40 bg-black/50"/><DialogPrimitive.Content className="fixed left-1/2 top-1/2 z-50 max-h-[90vh] w-[calc(100%-2rem)] max-w-2xl -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-xl bg-(--color-surface) p-5 text-(--color-ink) shadow-xl"><div className="flex items-center justify-between gap-3"><DialogPrimitive.Title className="text-xl font-bold">Добавить из диалога</DialogPrimitive.Title><DialogPrimitive.Close className="min-h-11 min-w-11 cursor-pointer rounded-lg border border-(--color-border)" aria-label="Закрыть">×</DialogPrimitive.Close></div><DialogPrimitive.Description className="mt-2 text-sm text-(--color-ink-muted)">Выберите диалог и реплику менеджера.</DialogPrimitive.Description><div className="mt-5 space-y-4"><div className="flex flex-wrap items-end gap-3"><label className="min-w-48 flex-1 text-sm">Поиск диалога<input value={search} onChange={event => setSearch(event.target.value)} className={field} placeholder="Имя или ID диалога" /></label><button type="button" disabled={loading} className={button} onClick={findDialogs}>{loading ? 'Загружаем…' : 'Найти'}</button></div>{dialogs.length === 0 && !loading && <p className="text-sm">Диалоги не выбраны или ничего не найдено.</p>}
        {loading && <div role="status" className="space-y-2"><span className="text-sm text-(--color-ink-muted)">Загружаем данные…</span><div aria-hidden="true" className="h-11 animate-pulse rounded-lg bg-(--color-surface-muted)"/><div aria-hidden="true" className="h-11 animate-pulse rounded-lg bg-(--color-surface-muted)"/></div>}
        {dialogs.length > 0 && <fieldset className="space-y-2"><legend className="text-sm font-semibold">Диалог</legend>{dialogs.map(dialog => <label className="flex cursor-pointer gap-2 rounded-lg bg-(--color-surface-muted) p-3 text-sm" key={dialog.id}><input type="radio" name="dialog" checked={sample.data.dialog === dialog.id} onChange={() => void chooseDialog(dialog.id)} />{dialog.name} · {dialog.id.slice(-6)}</label>)}</fieldset>}
        {sample.data.dialog && <fieldset className="space-y-2"><legend className="text-sm font-semibold">Реплика менеджера</legend>{messages.length === 0 && !loading ? <p className="text-sm">Реплик менеджера нет.</p> : messages.map(message => <label className="flex cursor-pointer gap-2 rounded-lg bg-(--color-surface-muted) p-3 text-sm" key={message.id}><input type="radio" name="message" checked={sample.data.message === message.id} onChange={() => sample.setData('message', message.id)} />{message.text}</label>)}</fieldset>}
        {maxWords !== null && selectedWords > maxWords && <p role="status" className="text-sm text-(--color-danger-ink)">Текст длиннее лимита персонажа: слов: {selectedWords}, лимит: {maxWords}.</p>}
        <form className="space-y-3" onSubmit={event => { event.preventDefault(); sample.transform(data => ({ ...data, version })); sample.post('/seller/knowledge/fewshot/from-dialog', { preserveScroll: true, onStart: () => setError(''), onNetworkError: () => { setError('Нет связи. Повторите добавление.'); return false; }, onSuccess: () => { onOpenChange(false); sample.reset(); } }); }}><label className="block text-sm">Тег<select required value={sample.data.tag} onChange={event => sample.setData('tag', event.target.value)} className={`${field} cursor-pointer`}><option value="">Выберите тег</option>{tags.map(tag => <option key={tag} value={tag}>{tag}</option>)}</select></label><label className="block text-sm">Ситуация<input maxLength={500} value={sample.data.situation} onChange={event => sample.setData('situation', event.target.value)} className={field} /></label><label className="block text-sm">Уверенность<input aria-label="Уверенность" type="range" min="0" max="1" step="0.01" value={sample.data.confidence} onChange={event => sample.setData('confidence', Number(event.target.value))} className="mt-2 block w-full cursor-pointer" /><output className="mt-1 block text-sm">{sample.data.confidence.toFixed(2)}</output></label><button disabled={sample.processing || !sample.data.message} className={`${button} bg-(--color-link) text-white`}>Добавить образец</button>{Object.values(sample.errors).map((message, index) => <p role="alert" className="text-sm text-(--color-danger-ink)" key={index}>{message}</p>)}</form>
      </div>{error && <p role="alert" className="mt-3 text-sm text-(--color-danger-ink)">{error}</p>}</DialogPrimitive.Content></DialogPrimitive.Portal></DialogPrimitive.Root>
    </section>
  </div>;
}
