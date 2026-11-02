import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';

type Bad = { text: string; why?: string };
type Dialog = { id: string; name: string };
type Message = { id: string; text: string };

const field = 'mt-1 block min-h-11 w-full rounded-lg border border-(--color-border) bg-(--color-surface) px-3 text-sm';
const button = 'min-h-11 cursor-pointer rounded-lg border border-(--color-border) px-4 text-sm font-semibold disabled:opacity-50';

export default function BadAndDialog({ version, badExamples }: { version: number; badExamples: Bad[] }) {
  const bad = useForm({ version, index: null as number | null, text: '', why: '' });
  const sample = useForm({ version, dialog: '', message: '', tag: '', situation: '', confidence: 0.9 });
  const [search, setSearch] = useState('');
  const [dialogs, setDialogs] = useState<Dialog[]>([]);
  const [messages, setMessages] = useState<Message[]>([]);
  const [open, setOpen] = useState(false);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  async function findDialogs() {
    setLoading(true); setError('');
    try {
      const response = await fetch(`/seller/knowledge/fewshot/dialogs?${new URLSearchParams({ search })}`, { headers: { Accept: 'application/json' } });
      if (!response.ok) throw new Error('Не удалось загрузить диалоги.');
      const data = await response.json() as { dialogs: Dialog[] };
      setDialogs(data.dialogs);
      setMessages([]);
      sample.setData('dialog', '');
      sample.setData('message', '');
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Ошибка загрузки.'); }
    finally { setLoading(false); }
  }

  async function chooseDialog(id: string) {
    sample.setData('dialog', id);
    sample.setData('message', '');
    setLoading(true); setError('');
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
    <section className="rounded-xl border border-(--color-border) bg-(--color-surface) p-5"><div className="flex flex-wrap items-center justify-between gap-3"><div><h2 className="text-xl font-bold">Из диалога</h2><p className="mt-1 text-sm text-(--color-ink-muted)">Выберите реплику менеджера из доступного вам диалога.</p></div><button type="button" className={button} onClick={() => setOpen(value => !value)} aria-expanded={open}>{open ? 'Скрыть' : 'Добавить из диалога'}</button></div>
      {open && <div className="mt-5 space-y-4"><div className="flex flex-wrap items-end gap-3"><label className="min-w-48 flex-1 text-sm">Поиск диалога<input value={search} onChange={event => setSearch(event.target.value)} className={field} placeholder="Имя или ID диалога" /></label><button type="button" disabled={loading} className={button} onClick={findDialogs}>{loading ? 'Загружаем…' : 'Найти'}</button></div>{dialogs.length === 0 && !loading && <p className="text-sm">Диалоги не выбраны или ничего не найдено.</p>}
        {dialogs.length > 0 && <fieldset className="space-y-2"><legend className="text-sm font-semibold">Диалог</legend>{dialogs.map(dialog => <label className="flex cursor-pointer gap-2 rounded-lg bg-(--color-surface-muted) p-3 text-sm" key={dialog.id}><input type="radio" name="dialog" checked={sample.data.dialog === dialog.id} onChange={() => void chooseDialog(dialog.id)} />{dialog.name} · {dialog.id.slice(-6)}</label>)}</fieldset>}
        {sample.data.dialog && <fieldset className="space-y-2"><legend className="text-sm font-semibold">Реплика менеджера</legend>{messages.length === 0 && !loading ? <p className="text-sm">Реплик менеджера нет.</p> : messages.map(message => <label className="flex cursor-pointer gap-2 rounded-lg bg-(--color-surface-muted) p-3 text-sm" key={message.id}><input type="radio" name="message" checked={sample.data.message === message.id} onChange={() => sample.setData('message', message.id)} />{message.text}</label>)}</fieldset>}
        <form className="space-y-3" onSubmit={event => { event.preventDefault(); sample.transform(data => ({ ...data, version })); sample.post('/seller/knowledge/fewshot/from-dialog', { preserveScroll: true, onSuccess: () => { setOpen(false); sample.reset(); } }); }}><label className="block text-sm">Тег<input required maxLength={100} value={sample.data.tag} onChange={event => sample.setData('tag', event.target.value)} className={field} /></label><label className="block text-sm">Ситуация<input maxLength={500} value={sample.data.situation} onChange={event => sample.setData('situation', event.target.value)} className={field} /></label><label className="block text-sm">Уверенность<input required type="number" min="0" max="1" step="0.01" value={sample.data.confidence} onChange={event => sample.setData('confidence', Number(event.target.value))} className={field} /></label><button disabled={sample.processing || !sample.data.message} className={`${button} bg-(--color-link) text-white`}>Добавить образец</button>{Object.values(sample.errors).map((message, index) => <p role="alert" className="text-sm text-(--color-danger-ink)" key={index}>{message}</p>)}</form>
      </div>}{error && <p role="alert" className="mt-3 text-sm text-(--color-danger-ink)">{error}</p>}
    </section>
  </div>;
}
