import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import YamlImport from './YamlImport';
import CabinetLayout from '@/layouts/CabinetLayout';
import SiteImport from './SiteImport';

type Editor = { version: number; groups: { key: string }[] } | null;
export default function Index({ editor, status }: { editor: Editor; status?: string | null }) {
  const [file, setFile] = useState<File | null>(null);
  const [proposals, setProposals] = useState<string[]>([]);
  const [selected, setSelected] = useState<number[]>([]);
  const [error, setError] = useState('');
  const [editing, setEditing] = useState<number | null>(null);
  const [busy, setBusy] = useState(false);
  const form = useForm({ version: editor?.version ?? 1, group: editor?.groups[0]?.key ?? '', facts: [] as string[] });
  async function preview() {
    if (!file) return;
    setBusy(true); setError(''); setProposals([]); setSelected([]); setEditing(null);
    try {
      const body = new FormData(); body.append('file', file);
      const token = document.cookie.split('; ').find(item => item.startsWith('XSRF-TOKEN='));
      const response = await fetch('/seller/knowledge/imports/preview', { method: 'POST', headers: { Accept: 'application/json', ...(token ? { 'X-XSRF-TOKEN': decodeURIComponent(token.slice(11)) } : {}) }, body });
      if (!response.ok) { const data = await response.json(); throw new Error(data.message || 'Ошибка файла'); }
      const data = await response.json() as { proposals: string[] }; setProposals(data.proposals);
      if (!data.proposals.length) setError('В файле нет непустых строк.');
    } catch (e) { setError(e instanceof Error ? e.message : 'Не удалось прочитать файл.'); } finally { setBusy(false); }
  }
  function save(e: React.FormEvent) {
    e.preventDefault();
    form.transform(data => ({ ...data, facts: selected.map(i => proposals[i]) }));
    form.post('/seller/knowledge/imports', { preserveScroll: true, onSuccess: page => { setSelected([]); setProposals([]); const updated = page.props.editor as Editor; if (updated) form.setData('version', updated.version); } });
  }
  return <CabinetLayout breadcrumbs={[{ label: 'Продавец', href: '/seller' }, { label: 'База знаний', href: '/seller/knowledge' }, { label: 'Импорт' }]}><Head title="Импорт знаний"/><main className="mx-auto max-w-[1000px]" data-private-profile>
    <h1 className="text-3xl font-bold">Документы и импорт</h1><p className="mt-2 text-sm text-(--color-ink-muted)">Проверьте предложения перед добавлением в черновик.</p>
    <section className="mt-5 rounded-xl border border-(--color-border) bg-(--color-surface) p-5"><h2 className="text-xl font-semibold">Загрузить файл</h2><p className="mt-2 text-sm">TXT, CSV или XLSX до 10 МБ; каждая строка станет предложением факта. PDF и DOCX появятся позже.</p><label onDragOver={event => event.preventDefault()} onDrop={event => { event.preventDefault(); setFile(event.dataTransfer.files[0] ?? null); setProposals([]); setSelected([]); setError(''); }} className="mt-4 block rounded-lg border border-dashed border-(--color-border) p-4 text-sm">Перетащите файл сюда или выберите его<input type="file" aria-label="Файл" accept=".txt,.csv,.xlsx" className="mt-2 block w-full cursor-pointer" onChange={e => { setFile(e.target.files?.[0] ?? null); setProposals([]); setSelected([]); setError(''); }}/></label>{file && <p className="mt-2 text-sm">Выбран файл: {file.name}</p>}<button type="button" disabled={!file || busy} onClick={preview} className="mt-4 min-h-11 cursor-pointer rounded-lg bg-(--color-link) px-4 text-sm font-semibold text-white disabled:opacity-50">{busy ? 'Читаем…' : 'Посмотреть предложения'}</button>{error && <p role="alert" className="mt-3 text-sm text-(--color-danger-ink)">{error}</p>}</section>
    {status && <p role="status" className="mt-4 text-sm">{status}</p>}
    <YamlImport/>
    <SiteImport editor={editor}/>
    {proposals.length > 0 && <form onSubmit={save} className="mt-4 rounded-xl border border-(--color-border) bg-(--color-surface) p-5"><h2 className="text-xl font-semibold">Сопоставление фактов</h2>{!editor ? <p className="mt-3 text-sm">Сначала <Link href="/seller/knowledge" className="cursor-pointer text-(--color-link)">создайте черновик</Link> и группу фактов.</p> : !editor.groups.length ? <p className="mt-3 text-sm">Сначала создайте группу фактов в черновике.</p> : <><label className="mt-3 block text-sm">Группа<select className="mt-1 block min-h-11 w-full cursor-pointer rounded border border-(--color-border) bg-(--color-surface) px-3" value={form.data.group} onChange={e => form.setData('group', e.target.value)}>{editor.groups.map(g => <option key={g.key} value={g.key}>{g.key}</option>)}</select></label><button type="button" className="mt-4 min-h-11 cursor-pointer rounded border border-(--color-border) px-3 text-sm" onClick={() => setSelected(proposals.map((_, i) => i))}>Принять все</button><div className="mt-4 space-y-3">{proposals.map((text, i) => <div key={i} className="rounded border border-(--color-border) p-3 text-sm"><label className="flex items-start gap-3"><input type="checkbox" checked={selected.includes(i)} onChange={e => setSelected(e.target.checked ? [...selected, i] : selected.filter(n => n !== i))}/><span>Принять факт {i + 1}</span></label>{editing === i ? <label className="mt-2 block">Текст факта<textarea maxLength={500} value={text} onChange={e => setProposals(proposals.map((value, n) => n === i ? e.target.value : value))} className="mt-1 block min-h-20 w-full rounded border border-(--color-border) p-2"/></label> : <p className="mt-2 break-words">{text}</p>}<div className="mt-2 flex flex-wrap gap-2"><button type="button" className="min-h-11 cursor-pointer rounded border border-(--color-border) px-3" onClick={() => { if (editing === i && !text.trim()) return; setEditing(editing === i ? null : i); }}>{editing === i ? 'Готово' : 'Изменить'}</button><button type="button" className="min-h-11 cursor-pointer rounded border border-(--color-border) px-3" onClick={() => { setProposals(proposals.filter((_, n) => n !== i)); setSelected(selected.filter(n => n !== i).map(n => n > i ? n - 1 : n)); setEditing(null); }}>Отклонить</button></div></div>)}</div><button disabled={!selected.length || form.processing || editing !== null || selected.some(i => !proposals[i].trim())} className="mt-4 min-h-11 cursor-pointer rounded-lg bg-(--color-link) px-4 text-sm font-semibold text-white disabled:opacity-50">Добавить выбранные в черновик</button>{Object.values(form.errors).map((value, i) => <p role="alert" key={i} className="mt-2 text-sm">{value}</p>)}</>}</form>}
  </main></CabinetLayout>;
}


