import { router } from '@inertiajs/react';
import { useState } from 'react';

type Template = {name: string; text: string};

export default function TemplatesEditor({ templates }: {templates: Template[]}) {
  const [open, setOpen] = useState(false);
  const [draft, setDraft] = useState<Template[]>([]);
  const [error, setError] = useState('');
  const [pending, setPending] = useState(false);
  const change = (index: number, field: keyof Template, value: string) => setDraft(items => items.map((item, i) => i === index ? {...item, [field]: value} : item));
  const save = () => {
    if (draft.some(item => !item.name.trim() || !item.text.trim())) { setError('Заполните название и текст каждого шаблона.'); return; }
    setError(''); setPending(true);
    router.put('/seller/dialogs/templates', {templates: draft}, {preserveScroll: true, onSuccess: () => setOpen(false), onError: () => setError('Не удалось сохранить шаблоны.'), onNetworkError: () => { setError('Не удалось связаться с сервером.'); return false; }, onFinish: () => setPending(false)});
  };
  if (!open) return <button type="button" className="cursor-pointer rounded-lg border border-(--color-border) px-3 text-sm" onClick={() => { setDraft(templates.map(item => ({...item}))); setError(''); setOpen(true); }}>Изменить шаблоны</button>;
  return <section aria-label="Редактирование шаблонов" className="space-y-3 rounded-lg border border-(--color-border) p-3"><h3 className="font-medium">Шаблоны фраз</h3>{draft.map((item, index) => <div key={index} className="space-y-2 rounded border border-(--color-border) p-2"><label className="block text-sm">Название<input value={item.name} maxLength={100} onChange={event => change(index, 'name', event.target.value)} className="block w-full rounded border border-(--color-border) bg-(--color-surface) p-2" /></label><label className="block text-sm">Текст<textarea value={item.text} maxLength={5000} onChange={event => change(index, 'text', event.target.value)} className="block w-full rounded border border-(--color-border) bg-(--color-surface) p-2" /></label><button type="button" className="cursor-pointer text-sm underline" onClick={() => setDraft(items => items.filter((_, i) => i !== index))}>Удалить</button></div>)}<div className="flex flex-wrap gap-2"><button type="button" className="cursor-pointer rounded border border-(--color-border) px-3 py-2 text-sm" disabled={draft.length >= 50} onClick={() => setDraft(items => [...items, {name: '', text: ''}])}>Добавить шаблон</button><button type="button" disabled={pending} className="cursor-pointer rounded border border-(--color-border) px-3 py-2 text-sm" onClick={save}>Сохранить шаблоны</button><button type="button" className="cursor-pointer px-3 py-2 text-sm" onClick={() => setOpen(false)}>Отмена</button></div>{error && <p role="alert" className="text-sm text-red-700">{error}</p>}</section>;
}
