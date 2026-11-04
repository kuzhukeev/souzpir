import { Button } from '@/components/ui/forms';
import type { QuestionSet } from '@/types/Vision/Questions';
import { dateUtc } from './copy';
import { card } from './styles';

export function SetDetails({
  value,
  canEdit,
  busy,
  onCopy,
}: {
  value: QuestionSet;
  canEdit: boolean;
  busy: boolean;
  onCopy: () => void;
}) {
  return (
    <>
      <div className="rounded-lg bg-(--color-brand-primary-soft) p-4 text-sm">
        Текущий набор используется до утверждения нового.
      </div>
      <section className={card}>
        <h2 className="text-xl font-bold">Версия {value.version} · Активный</h2>
        <p className="mt-2 text-sm text-(--color-ink-muted)">
          Утверждён: {dateUtc(value.created_at)} · автор: {value.approver_name ?? 'Не указан'} ·
          вопросов: {value.size}
        </p>
        <p className="mt-2 text-sm">
          Модели: {Object.values(value.providers ?? {}).join(', ') || 'Не указаны'}
        </p>
        <p className="mt-2 text-sm">Частота: {({weekly:'Еженедельно',biweekly:'Раз в две недели',monthly:'Ежемесячно'} as Record<string,string>)[value.frequency ?? ''] ?? 'Вручную'}</p>
        <p className="mt-2 break-all text-sm text-(--color-ink-muted)">
          Контрольная сумма: {value.checksum}
        </p>
        <ol className="mt-5 list-inside list-decimal space-y-3">
          {value.questions.map((q) => (
            <li key={q.id} className="text-sm">
              {q.text}
            </li>
          ))}
        </ol>
        <div className="mt-5 rounded-lg bg-(--color-surface-muted) p-4">
          <h3 className="font-semibold">Пример данных</h3>
          {value.questions.length === 0 ? <p className="mt-2 text-sm">В наборе пока нет вопросов.</p> : <>
            <p className="mt-2 text-sm">Вопрос: {value.questions[0].text}</p>
            <p className="mt-1 text-sm">Ответы моделей: {Object.values(value.providers ?? {}).join(', ') || 'Модели не выбраны'}.</p>
            <p className="mt-1 text-sm text-(--color-ink-muted)">После замера в отчёте будут ответы, упоминания и источники по каждому вопросу.</p>
          </>}
        </div>
        <p className="mt-4 text-sm text-(--color-ink-muted)">Только для чтения.</p>
        {canEdit && (
          <Button className="mt-4" loading={busy} onClick={onCopy}>
            Изменить набор
          </Button>
        )}
      </section>
    </>
  );
}
