import { Cpu } from 'lucide-react';

type TimelineEvent = { kind: 'first_answer' | 'first_error'; at: string };

type Answer = { question_n: number; provider: string; requested_model?: string; status: string };

const settled = (status: string) => ['ok', 'failed', 'uncertain'].includes(status);

export default function RunProgress({ answers, status, createdAt, startedAt, finishedAt, responseEvents = [] }: { answers: Answer[]; status: string; createdAt?: string | null; startedAt?: string | null; finishedAt?: string | null; responseEvents?: TimelineEvent[] }) {
  const events: [string, string][] = [['Создан', createdAt], ['Начат', startedAt], ['Завершён', finishedAt]].filter((event): event is [string, string] => Boolean(event[1]));
  for (const event of responseEvents) {
    if (event.at && Number.isFinite(Date.parse(event.at)) && ['first_answer', 'first_error'].includes(event.kind)) events.push([event.kind === 'first_answer' ? 'Получены первые ответы' : 'Одна из моделей вернула ошибку', event.at]);
  }
  events.sort((a, b) => Date.parse(a[1]) - Date.parse(b[1]));
  const eventList = events.length > 0 && <div className="rounded-xl border border-(--color-border) p-4"><h3 className="text-lg font-semibold">События замера</h3><ol className="mt-3 border-l-2 border-(--color-border) pl-4">{events.map(([label, value])=><li key={label} className="relative flex flex-wrap gap-x-4 gap-y-1 border-b border-(--color-border) py-3 last:border-0"><span aria-hidden="true" className={`absolute -left-[1.42rem] top-4 size-3 rounded-full ${label === 'Одна из моделей вернула ошибку' ? 'bg-(--color-danger-ink)' : label === 'Получены первые ответы' ? 'bg-(--color-info-ink)' : 'bg-(--color-success-ink)'}`}/><time className="text-(--color-ink-muted)" dateTime={value}>{new Date(value).toLocaleString('ru-RU')}</time><span>{label}</span></li>)}</ol></div>;
  if (!answers.length) return <div className="mt-3 space-y-3 text-sm"><p>Слоты замера пока не созданы.</p>{eventList}{responseEvents.length === 0 && <p className="text-(--color-ink-muted)">Время первых ответов и ошибок пока недоступно.</p>}</div>;

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
    <progress className="h-3 w-full overflow-hidden rounded-full accent-(--color-action)" aria-label="Обработанные слоты" value={done} max={answers.length} />
    <div><h3 className="font-semibold">Статусы моделей</h3><ul className="mt-2 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">{providers.map(provider => {
      const group = answers.filter(answer => answer.provider === provider);
      const received = group.filter(answer => settled(answer.status)).length;
      const label = group.some(answer => ['failed', 'uncertain'].includes(answer.status)) ? 'Ошибка'
        : received === group.length ? 'Готово'
          : group.some(answer => ['in_progress', 'running', 'ok'].includes(answer.status)) ? 'Отвечает' : 'Ожидает';
      return <li key={provider} className="rounded-lg border border-(--color-border) bg-(--color-surface) p-4"><Cpu aria-hidden="true" className="mb-2 size-6 text-(--color-ink-muted)"/><span className={`inline-block rounded-full px-3 py-1 font-semibold ${label === 'Ошибка' ? 'bg-(--color-danger-soft) text-(--color-danger-ink)' : label === 'Готово' ? 'bg-(--color-success-soft) text-(--color-success-ink)' : label === 'Отвечает' ? 'bg-(--color-info-soft) text-(--color-info-ink)' : 'bg-(--color-surface-muted) text-(--color-ink-muted)'}`}>{provider}: {label} · {received} из {group.length}</span>{group.some(answer=>answer.requested_model) && <p className="mt-1 text-(--color-ink-muted)">Модели: {Array.from(new Set(group.map(answer=>answer.requested_model).filter(Boolean))).join(', ')}</p>}</li>;
    })}</ul></div>
    <div><h3 className="font-semibold">Ответы по вопросам</h3><ul className="mt-2 space-y-2">{answers.map((answer, index) => {
      const label = answer.status === 'ok' ? 'Получен' : answer.status === 'failed' ? 'Ошибка' : answer.status === 'uncertain' ? 'Требует проверки' : ['in_progress', 'running'].includes(answer.status) ? 'Готовится' : 'Ожидается';
      return <li key={`${answer.question_n}-${answer.provider}-${answer.requested_model ?? ''}-${index}`} className="rounded-lg border border-(--color-border) p-3">Вопрос {answer.question_n} · {answer.requested_model || answer.provider} · {label}</li>;
    })}</ul></div>
    {eventList}
    {responseEvents.length === 0 && <p className="text-(--color-ink-muted)">Время первых ответов и ошибок пока недоступно.</p>}
    {['pending', 'running', 'in_progress'].includes(status) && <a className="inline-flex min-h-11 cursor-pointer items-center font-semibold text-(--color-link)" href="/vision/settings">Настройки уведомлений</a>}
  </div>;
}
