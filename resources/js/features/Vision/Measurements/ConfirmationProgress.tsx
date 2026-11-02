type Answer = { status: string };

export default function ConfirmationProgress({
  status,
  answers,
  confirmed = false,
  canConfirm = false,
  loading = false,
  onConfirm,
}: {
  status: string;
  answers: Answer[];
  confirmed?: boolean;
  canConfirm?: boolean;
  loading?: boolean;
  onConfirm?: () => void;
}) {
  const count = answers.filter((answer) => answer.status === 'ok').length;
  const finished = status === 'completed';
  return (
    <section
      className="mt-4 rounded-lg bg-(--color-surface-muted) p-4"
      aria-label="Подтверждение снимка"
    >
      <h3 className="font-semibold">Подтверждение снимка</h3>
      <ol className="mt-3 grid gap-2 sm:grid-cols-3">
        <li>
          <span className="font-medium">1. Замер</span>
          <p>{finished ? 'Завершён' : 'Не завершён'}</p>
        </li>
        <li>
          <span className="font-medium">2. Ответы</span>
          <p>{answers.length ? `Получено ${count} из ${answers.length}` : 'Ответов пока нет'}</p>
        </li>
        <li>
          <span className="font-medium">3. Подтверждение</span>
          <p>{confirmed ? 'Подтверждён' : 'Не подтверждён'}</p>
        </li>
      </ol>
      <p className="mt-3 text-(--color-ink-muted)">
        {confirmed ? 'Снимок подтверждён.' : 'Снимок не подтверждён.'}
      </p>
      {!confirmed && onConfirm && (
        <button
          type="button"
          disabled={!finished || !canConfirm || loading}
          onClick={onConfirm}
          className="mt-3 min-h-11 cursor-pointer rounded-lg border border-(--color-border) px-4 text-sm font-semibold text-(--color-link) disabled:cursor-not-allowed disabled:opacity-50"
        >
          {loading ? 'Сохраняем…' : 'Подтвердить снимок'}
        </button>
      )}
    </section>
  );
}
