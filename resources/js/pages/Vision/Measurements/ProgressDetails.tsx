type Answer = { question_n: number; provider: string; requested_model?: string; status: string };

const settled = (status: string) => ['ok', 'failed', 'uncertain'].includes(status);

export default function RunProgress({ answers, status, createdAt, startedAt, finishedAt }: { answers: Answer[]; status: string; createdAt?: string | null; startedAt?: string | null; finishedAt?: string | null }) {
  const events = [['Создан', createdAt], ['Начат', startedAt], ['Завершён', finishedAt]].filter((event): event is [string, string] => Boolean(event[1]));
  const eventList = events.length > 0 && <div><h3 className="font-semibold">События замера</h3><ul className="mt-2 space-y-1">{events.map(([label, value])=><li key={label}>{label} · <time dateTime={value}>{new Date(value).toLocaleString('ru-RU')}</time></li>)}</ul></div>;
  if (!answers.length) return <div className="mt-3 space-y-3 text-sm"><p>Слоты замера пока не созданы.</p>{eventList}</div>;

  const done = answers.filter(answer => settled(answer.status)).length;
  const received = answers.filter(answer => answer.status === 'ok').length;
  const errors = answers.filter(answer => answer.status === 'failed').length;
  const uncertain = answers.filter(answer => answer.status === 'uncertain').length;
  const questions = Array.from(new Set(answers.map(answer => answer.question_n)));
  const completeQuestions = questions.filter(number => answers.filter(answer => answer.question_n === number).every(answer => settled(answer.status))).length;
  const providers = Array.from(new Set(answers.map(answer => answer.provider)));

  return <div className="mt-3 space-y-3 text-sm">
    <p>{done} из {answers.length} слотов обработано · вопросов обработано {completeQuestions} из {questions.length}</p>
    <p>Получено ответов: {received} · Ошибок: {errors} · Требуют проверки: {uncertain}</p>
    <progress className="w-full accent-(--color-action)" aria-label="Полученные ответы" value={done} max={answers.length} />
    <div><h3 className="font-semibold">Статусы моделей</h3><ul className="mt-2 grid gap-2 sm:grid-cols-2">{providers.map(provider => {
      const group = answers.filter(answer => answer.provider === provider);
      const received = group.filter(answer => settled(answer.status)).length;
      const label = group.some(answer => ['failed', 'uncertain'].includes(answer.status)) ? 'Ошибка'
        : received === group.length ? 'Готово'
          : group.some(answer => ['in_progress', 'running', 'ok'].includes(answer.status)) ? 'Отвечает' : 'Ожидает';
      return <li key={provider} className="rounded-lg bg-(--color-surface-muted) p-3"><span>{provider}: {label} · {received} из {group.length}</span>{group.some(answer=>answer.requested_model) && <p className="mt-1 text-(--color-ink-muted)">Модели: {Array.from(new Set(group.map(answer=>answer.requested_model).filter(Boolean))).join(', ')}</p>}</li>;
    })}</ul></div>
    <div><h3 className="font-semibold">Ответы по вопросам</h3><ul className="mt-2 space-y-2">{answers.map((answer, index) => {
      const label = answer.status === 'ok' ? 'Получен' : answer.status === 'failed' ? 'Ошибка' : answer.status === 'uncertain' ? 'Требует проверки' : ['in_progress', 'running'].includes(answer.status) ? 'Готовится' : 'Ожидается';
      return <li key={`${answer.question_n}-${answer.provider}-${answer.requested_model ?? ''}-${index}`} className="rounded-lg border border-(--color-border) p-3">Вопрос {answer.question_n} · {answer.requested_model || answer.provider} · {label}</li>;
    })}</ul></div>
    {eventList}
    {['pending', 'running', 'in_progress'].includes(status) && <a className="inline-flex min-h-11 cursor-pointer items-center font-semibold text-(--color-link)" href="/vision/settings">Настройки уведомлений</a>}
    {status === 'completed' && <p className="rounded-lg bg-(--color-success-soft) p-3">Замер готов. <a className="cursor-pointer font-semibold text-(--color-link)" href="/vision/clarifications">Проверить уточнения</a></p>}
  </div>;
}
