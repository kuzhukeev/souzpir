type Answer = { question_n: number; provider: string; status: string };

const settled = (status: string) => ['ok', 'failed', 'uncertain'].includes(status);

export default function RunProgress({ answers, status }: { answers: Answer[]; status: string }) {
  if (!answers.length) return <p className="mt-3 text-sm">Слоты замера пока не созданы.</p>;

  const done = answers.filter(answer => settled(answer.status)).length;
  const questions = Array.from(new Set(answers.map(answer => answer.question_n)));
  const completeQuestions = questions.filter(number => answers.filter(answer => answer.question_n === number).every(answer => settled(answer.status))).length;
  const providers = Array.from(new Set(answers.map(answer => answer.provider)));

  return <div className="mt-3 space-y-3 text-sm">
    <p>{done} из {answers.length} ответов получено · вопросов завершено {completeQuestions} из {questions.length}</p>
    <progress className="w-full accent-(--color-action)" aria-label="Полученные ответы" value={done} max={answers.length} />
    <div><h3 className="font-semibold">Статусы моделей</h3><ul className="mt-2 grid gap-2 sm:grid-cols-2">{providers.map(provider => {
      const group = answers.filter(answer => answer.provider === provider);
      const received = group.filter(answer => settled(answer.status)).length;
      const label = group.some(answer => ['failed', 'uncertain'].includes(answer.status)) ? 'Ошибка'
        : received === group.length ? 'Готово'
          : group.some(answer => ['in_progress', 'running', 'ok'].includes(answer.status)) ? 'Отвечает' : 'Ожидает';
      return <li key={provider} className="rounded-lg bg-(--color-surface-muted) p-3">{provider}: {label} · {received} из {group.length}</li>;
    })}</ul></div>
    {['pending', 'running', 'in_progress'].includes(status) && <a className="inline-flex min-h-11 cursor-pointer items-center font-semibold text-(--color-link)" href="/vision/settings">Уведомить, когда будет готово</a>}
    {status === 'completed' && <p className="rounded-lg bg-(--color-success-soft) p-3">Замер готов. <a className="cursor-pointer font-semibold text-(--color-link)" href="/vision/clarifications">Проверить уточнения</a></p>}
  </div>;
}
