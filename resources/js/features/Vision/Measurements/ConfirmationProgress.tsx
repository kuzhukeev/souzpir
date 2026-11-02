type Answer = { status: string };

export default function ConfirmationProgress({
  status,
  answers,
}: {
  status: string;
  answers: Answer[];
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
          <p>Пока недоступно</p>
        </li>
      </ol>
      <p className="mt-3 text-(--color-ink-muted)">
        Снимок не подтверждён. Сохранение подтверждения появится после подключения.
      </p>
    </section>
  );
}
