import { Head, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import CabinetLayout from '@/layouts/CabinetLayout';
import { Select } from '@/components/ui';

type Task = { id: string; type: string; assigned_user_id: string; assignee: string };
type Person = { id: string; name: string };

export default function Transfer({ tasks, recipients }: { tasks: Task[]; recipients: Person[] }) {
  const [taskId, setTaskId] = useState('');
  const [recipientId, setRecipientId] = useState('');
  const [pending, setPending] = useState(false);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  const selected = tasks.find((task) => task.id === taskId);
  const options = recipients.filter((person) => person.id !== selected?.assigned_user_id);

  async function submit(event: FormEvent) {
    event.preventDefault();
    if (!selected || !recipientId) return;
    setPending(true);
    setError('');
    setMessage('');
    try {
      const cookie = document.cookie.split('; ').find((item) => item.startsWith('XSRF-TOKEN='));
      const response = await fetch(`/seller/tasks/${encodeURIComponent(selected.id)}/transfer`, {
        method: 'POST', credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(cookie ? { 'X-XSRF-TOKEN': decodeURIComponent(cookie.slice(11)) } : {}) },
        body: JSON.stringify({ assigned_user_id: recipientId }),
      });
      if (!response.ok) {
        const result = await response.json().catch(() => ({}));
        const detail = result.errors?.task?.[0] || result.errors?.assigned_user_id?.[0];
        throw new Error(typeof detail === 'string' ? detail : 'Не удалось передать задачу. Обновите страницу и попробуйте снова.');
      }
      setMessage('Задача передана сотруднику.');
      setTaskId('');
      setRecipientId('');
      router.reload({ only: ['tasks', 'recipients'] });
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Не удалось передать задачу.');
    } finally {
      setPending(false);
    }
  }

  return (
    <CabinetLayout breadcrumbs={[{ label: 'Главная' }, { label: 'Продавец' }, { label: 'Передача задач' }]}>
      <Head title="Передача задач" />
      <div className="max-w-2xl">
        <h1 className="text-3xl font-bold">Передача задач</h1>
        <p className="mt-2 text-base text-(--color-ink-muted)">Выберите активное назначение и сотрудника текущего бренда.</p>
        {message && <p role="status" className="mt-4 text-sm text-(--color-success)">{message}</p>}
        {error && <p role="alert" className="mt-4 text-sm text-(--color-danger)">{error}</p>}
        {tasks.length === 0 ? <p className="mt-6 rounded-xl bg-(--color-surface) p-6 text-sm">Активных назначений пока нет.</p> : (
          <form onSubmit={submit} className="mt-6 space-y-5 rounded-xl bg-(--color-surface) p-6">
            <Select label="Активная задача" value={taskId} placeholder="Выберите задачу" options={tasks.map((task) => ({ value: task.id, label: `Задача ${task.id} · ${task.assignee}` }))} onValueChange={(value) => { setTaskId(value); setRecipientId(''); }} />
            <Select label="Сотрудник" value={recipientId} disabled={!selected || pending} placeholder="Выберите сотрудника" options={options.map((person) => ({ value: person.id, label: person.name }))} onValueChange={setRecipientId} />
            {selected && options.length === 0 && <p className="text-sm text-(--color-ink-muted)">Нет подходящих сотрудников для передачи.</p>}
            <button type="submit" disabled={!selected || !recipientId || pending} className="cursor-pointer rounded-lg bg-(--color-action) px-5 py-3 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-60">{pending ? 'Передаём…' : 'Передать задачу'}</button>
          </form>
        )}
      </div>
    </CabinetLayout>
  );
}

