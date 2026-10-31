import { router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Modal, Select } from '@/components/ui';
import type { Achievement, Comparison, Member, SiteAction } from '@/types/Vision/Site';

const card =
  'rounded-2xl border border-(--color-border) bg-(--color-surface) p-5 shadow-(--shadow-card)';
const input =
  'min-h-11 w-full rounded-lg border border-(--color-border) bg-(--color-surface) px-3 py-2 text-base text-(--color-ink) focus-visible:outline-2 focus-visible:outline-(--color-focus)';
const primary =
  'inline-flex min-h-11 cursor-pointer items-center justify-center rounded-lg bg-(--color-action) px-4 py-2 text-sm font-semibold text-white hover:bg-(--color-action-hover) disabled:cursor-not-allowed disabled:opacity-60';
const secondary =
  'inline-flex min-h-11 cursor-pointer items-center justify-center rounded-lg border border-(--color-border) bg-(--color-surface) px-4 py-2 text-sm font-semibold text-(--color-link) hover:bg-(--color-surface-muted) disabled:cursor-not-allowed disabled:opacity-60';
function Errors({ errors }: { errors: Record<string, string> }) {
  return Object.values(errors).length ? (
    <div role="alert" className="rounded-lg bg-(--color-surface-muted) p-3">
      {Object.values(errors).map((error, i) => (
        <p key={i}>{error}</p>
      ))}
    </div>
  ) : null;
}
function formatDate(value?: string | null) {
  if (!value) return '—';
  const parsed = new Date(value);
  return Number.isNaN(parsed.getTime()) ? value : parsed.toLocaleDateString('ru-RU');
}
function Responsible({
  members,
  value,
  change,
}: {
  members: Member[];
  value: string;
  change: (value: string) => void;
}) {
  return (
    <Select
      label="Ответственный"
      value={value || 'none'}
      onValueChange={(v) => change(v === 'none' ? '' : v)}
      options={[
        { value: 'none', label: 'Не назначен' },
        ...members.map((member) => ({ value: member.id, label: member.name })),
      ]}
    />
  );
}
function Complete({ action }: { action: SiteAction }) {
  const [open, setOpen] = useState(false);
  const form = useForm({
    status: 'awaiting_measurement',
    completed_at: '',
    comment: '',
    evidence_url: '',
  });
  function submit(event: FormEvent) {
    event.preventDefault();
    form.patch(`/vision/actions/${action.id}`, {
      preserveScroll: true,
      onSuccess: () => {
        setOpen(false);
        form.reset();
      },
    });
  }
  return (
    <>
      <button type="button" className={primary} onClick={() => setOpen(true)}>
        Отметить выполненным
      </button>
      <Modal open={open} title="Отметить выполненным" onClose={() => setOpen(false)}>
        <p className="mb-4 text-(--color-ink-muted)">{action.title}</p>
        <form onSubmit={submit} className="space-y-4">
          <label className="block font-semibold">
            Дата выполнения
            <input
              required
              type="date"
              className={`${input} mt-1`}
              value={form.data.completed_at}
              onChange={(e) => form.setData('completed_at', e.target.value)}
            />
          </label>
          <label className="block font-semibold">
            Что сделано
            <textarea
              required
              className={`${input} mt-1`}
              value={form.data.comment}
              onChange={(e) => form.setData('comment', e.target.value)}
            />
          </label>
          <label className="block font-semibold">
            Ссылка-доказательство{' '}
            <span className="font-normal text-(--color-ink-muted)">необязательно</span>
            <input
              type="url"
              className={`${input} mt-1`}
              value={form.data.evidence_url}
              onChange={(e) => form.setData('evidence_url', e.target.value)}
            />
          </label>
          <p className="rounded-lg bg-(--color-surface-muted) p-3">
            После сохранения действие перейдёт в статус «Ждём замера».
          </p>
          <Errors errors={form.errors} />
          <div className="flex flex-wrap gap-3">
            <button type="button" className={secondary} onClick={() => setOpen(false)}>
              Отмена
            </button>
            <button disabled={form.processing} className={primary}>
              {form.processing ? 'Сохраняем…' : 'Отметить выполненным'}
            </button>
          </div>
        </form>
      </Modal>
    </>
  );
}
function ActionCard({
  action,
  canManage,
  members,
}: {
  action: SiteAction;
  canManage: boolean;
  members: Member[];
}) {
  const [editing, setEditing] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [failure, setFailure] = useState('');
  const form = useForm({
    title: action.title,
    description: action.description || '',
    responsible: action.responsible || '',
    due_date: action.due_date?.slice(0, 10) || '',
    comment: action.comment || '',
  });
  function save(event: FormEvent) {
    event.preventDefault();
    form.patch(`/vision/actions/${action.id}`, {
      preserveScroll: true,
      onSuccess: () => setEditing(false),
    });
  }
  function remove() {
    if (!window.confirm('Удалить действие?')) return;
    router.delete(`/vision/actions/${action.id}`, {
      preserveScroll: true,
      onStart: () => {
        setFailure('');
        setDeleting(true);
      },
      onFinish: () => setDeleting(false),
      onError: () => setFailure('Не удалось удалить действие. Попробуйте снова.'),
    });
  }
  return (
    <article className={`${card} flex flex-col gap-4`}>
      <div>
        <h3 className="break-words text-lg font-semibold">{action.title}</h3>
        <p className="mt-1 text-sm text-(--color-ink-muted)">
          {action.status === 'awaiting_measurement' ? 'Ждём замера' : 'В работе'}
        </p>
      </div>
      {editing ? (
        <form onSubmit={save} className="space-y-3">
          <label className="block text-sm font-semibold">
            Название
            <input
              required
              maxLength={255}
              className={`${input} mt-1`}
              value={form.data.title}
              onChange={(e) => form.setData('title', e.target.value)}
            />
          </label>
          <label className="block text-sm font-semibold">
            Описание
            <textarea
              className={`${input} mt-1`}
              value={form.data.description}
              onChange={(e) => form.setData('description', e.target.value)}
            />
          </label>
          <label className="block text-sm font-semibold">
            Комментарий
            <textarea
              className={`${input} mt-1`}
              value={form.data.comment}
              onChange={(e) => form.setData('comment', e.target.value)}
            />
          </label>
          <Responsible
            members={members}
            value={form.data.responsible}
            change={(v) => form.setData('responsible', v)}
          />
          <label className="block text-sm font-semibold">
            Срок
            <input
              type="date"
              className={`${input} mt-1`}
              value={form.data.due_date}
              onChange={(e) => form.setData('due_date', e.target.value)}
            />
          </label>
          <Errors errors={form.errors} />
          <div className="flex flex-wrap gap-2">
            <button className={primary} disabled={form.processing}>
              Сохранить
            </button>
            <button type="button" className={secondary} onClick={() => setEditing(false)}>
              Отмена
            </button>
          </div>
        </form>
      ) : (
        <>
          {action.description && (
            <p className="whitespace-pre-wrap break-words text-(--color-ink-muted)">
              {action.description}
            </p>
          )}
          {action.comment && (
            <p className="whitespace-pre-wrap break-words text-sm">Комментарий: {action.comment}</p>
          )}
          <p className="text-sm text-(--color-ink-muted)">
            Ответственный: {members.find((m) => m.id === action.responsible)?.name || 'Не назначен'}
            <br />
            Срок: {formatDate(action.due_date)}
          </p>
          {action.completed_at && (
            <p className="text-sm">Выполнено: {formatDate(action.completed_at)}</p>
          )}
          {action.evidence_url && (
            <a
              href={action.evidence_url}
              target="_blank"
              rel="noopener noreferrer"
              className="cursor-pointer break-all text-(--color-link) underline"
            >
              Ссылка-доказательство
            </a>
          )}
          {canManage && (
            <div className="mt-auto flex flex-wrap gap-2">
              <button type="button" className={secondary} onClick={() => setEditing(true)}>
                Изменить
              </button>
              <button type="button" className={secondary} onClick={remove} disabled={deleting}>
                Удалить
              </button>
              {action.status === 'open' && <Complete action={action} />}
            </div>
          )}
          {failure && <p role="alert">{failure}</p>}
        </>
      )}
    </article>
  );
}
function CreateAction({ members }: { members: Member[] }) {
  const form = useForm({ title: '', description: '', responsible: '', due_date: '', comment: '' });
  function submit(event: FormEvent) {
    event.preventDefault();
    form.post('/vision/actions', { preserveScroll: true, onSuccess: () => form.reset() });
  }
  return (
    <details className={card}>
      <summary className="cursor-pointer font-semibold">Добавить действие</summary>
      <form onSubmit={submit} className="mt-4 space-y-3">
        <label className="block text-sm font-semibold">
          Название
          <input
            required
            maxLength={255}
            className={`${input} mt-1`}
            value={form.data.title}
            onChange={(e) => form.setData('title', e.target.value)}
          />
        </label>
        <label className="block text-sm font-semibold">
          Описание
          <textarea
            className={`${input} mt-1`}
            value={form.data.description}
            onChange={(e) => form.setData('description', e.target.value)}
          />
        </label>
        <label className="block text-sm font-semibold">
          Комментарий
          <textarea
            className={`${input} mt-1`}
            value={form.data.comment}
            onChange={(e) => form.setData('comment', e.target.value)}
          />
        </label>
        <Responsible
          members={members}
          value={form.data.responsible}
          change={(v) => form.setData('responsible', v)}
        />
        <label className="block text-sm font-semibold">
          Срок
          <input
            type="date"
            className={`${input} mt-1`}
            value={form.data.due_date}
            onChange={(e) => form.setData('due_date', e.target.value)}
          />
        </label>
        <Errors errors={form.errors} />
        <button className={primary} disabled={form.processing}>
          {form.processing ? 'Сохраняем…' : 'Добавить'}
        </button>
      </form>
    </details>
  );
}
export function Actions({
  actions,
  canManage,
  history,
  members,
  achievements = [],
}: {
  actions: SiteAction[];
  canManage: boolean;
  history: boolean;
  members: Member[];
  achievements?: Achievement[];
  comparison?: Comparison | null;
}) {
  const visible = actions.filter((action) =>
    history ? action.status === 'awaiting_measurement' : action.status === 'open',
  );
  const first = history ? visible : visible.slice(0, 3);
  const remaining = history ? [] : visible.slice(3);
  const cards = (items: SiteAction[]) => (
    <div className="grid gap-4 lg:grid-cols-3">
      {items.map((action) => (
        <ActionCard key={action.id} action={action} canManage={canManage} members={members} />
      ))}
    </div>
  );
  return (
    <div className="space-y-4">
      {history && <HistoryInsights achievements={achievements} />}
      {!history && canManage && <CreateAction members={members} />}
      {first.length ? (
        cards(first)
      ) : (
        <div className={`${card} text-(--color-ink-muted)`}>
          {history ? 'Выполненных действий пока нет.' : 'Действий пока нет.'}
        </div>
      )}
      {remaining.length > 0 && (
        <details className={card}>
          <summary className="cursor-pointer font-semibold">Остальные рекомендации</summary>
          <div className="mt-4">{cards(remaining)}</div>
        </details>
      )}
      {history && (
        <p className="text-sm text-(--color-ink-muted)">
          Эффект отдельных действий: рано судить. Связь с затронутыми вопросами не установлена.
        </p>
      )}
    </div>
  );
}
function HistoryInsights({ achievements }: { achievements: Achievement[] }) {
  return (
    <>
      <section className={card}>
        <h2 className="text-xl font-semibold">Текущие наблюдения</h2>
        <p className="mt-2 text-sm text-(--color-ink-muted)">
          Данные из последнего доступного подтверждённого отчёта могут измениться после нового
          замера или отзыва доступа.
        </p>
        {achievements.length ? (
          <ul className="mt-4 grid gap-3 sm:grid-cols-2">
            {achievements.map((achievement) => (
              <li
                key={`${achievement.code}-${achievement.report_id || achievement.earned_at}`}
                className="rounded-lg bg-(--color-surface-muted) p-4"
              >
                <h3 className="font-semibold">{achievement.title}</h3>
                {achievement.note && <p className="mt-2 text-sm">{achievement.note}</p>}
              </li>
            ))}
          </ul>
        ) : (
          <p className="mt-3 text-(--color-ink-muted)">Подтверждённых наблюдений пока нет.</p>
        )}
      </section>
      <section className={card}>
        <h2 className="text-xl font-semibold">Сравнение замеров бренда</h2>
        <p className="mt-3 text-(--color-ink-muted)">
          Рано судить: порядок и сопоставимость замеров пока нельзя подтвердить.
        </p>
      </section>
    </>
  );
}
