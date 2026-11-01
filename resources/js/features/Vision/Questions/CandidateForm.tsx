import { useMemo, useState } from 'react';
import { Button, Textarea } from '@/components/ui/forms';
import { Modal } from '@/components/ui/overlay';
import { intents } from './copy';
import { Alert } from './Alert';
import type { Candidate, Intent } from '@/types/Vision/Questions';
const intentKeys = Object.keys(intents) as Intent[];

export function CandidateForm({
  open,
  onClose,
  onSave,
  initial,
  all,
  busy,
  error,
}: {
  open: boolean;
  onClose: () => void;
  onSave: (text: string, intent: Intent) => void;
  initial: Candidate | null;
  all: Candidate[];
  busy: boolean;
  error: string;
}) {
  const [text, setText] = useState(initial?.text ?? '');
  const [intent, setIntent] = useState<Intent>(initial?.intent ?? 'choose');
  const similar = useMemo(() => {
    const normalized = text.trim().toLocaleLowerCase('ru');
    return normalized.length >= 6
      ? all
          .filter(
            (q) =>
              q.id !== initial?.id &&
              (q.text.toLocaleLowerCase('ru').includes(normalized) ||
                normalized.includes(q.text.toLocaleLowerCase('ru'))),
          )
          .slice(0, 3)
      : [];
  }, [text, all, initial]);
  return (
    <Modal
      open={open}
      title={initial ? 'Изменить вопрос' : 'Добавить свой вопрос'}
      onClose={onClose}
    >
      <form
        className="space-y-5"
        onSubmit={(event) => {
          event.preventDefault();
          onSave(text.trim(), intent);
        }}
      >
        <Alert message={error} />
        <Textarea
          label="Текст вопроса"
          hint="Пишите так, как спросил бы покупатель, без названия вашей компании."
          value={text}
          onChange={(event) => setText(event.target.value)}
          maxLength={4000}
          required
          rows={4}
        />
        <fieldset>
          <legend className="mb-2 text-sm font-semibold">Намерение покупателя</legend>
          <div className="space-y-2">
            {intentKeys.map((key) => (
              <label key={key} className="flex min-h-9 cursor-pointer items-center gap-2">
                <input
                  type="radio"
                  name="intent"
                  checked={intent === key}
                  onChange={() => setIntent(key)}
                />
                {intents[key]}
              </label>
            ))}
          </div>
        </fieldset>
        {similar.length > 0 && (
          <div className="rounded-lg bg-amber-50 p-4 text-sm text-amber-900">
            <p className="font-semibold">Похожий вопрос уже есть</p>
            <ul className="mt-2 list-inside list-disc">
              {similar.map((item) => (
                <li key={item.id}>{item.text}</li>
              ))}
            </ul>
            <p className="mt-2">Вы можете добавить вопрос, если он отличается по смыслу.</p>
          </div>
        )}
        <div className="flex justify-end gap-2">
          <Button variant="secondary" type="button" onClick={onClose}>
            Отмена
          </Button>
          <Button type="submit" loading={busy} disabled={!text.trim()}>
            {initial ? 'Сохранить' : 'Добавить вопрос'}
          </Button>
        </div>
      </form>
    </Modal>
  );
}
