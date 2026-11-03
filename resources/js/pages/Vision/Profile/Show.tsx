import * as Dialog from '@radix-ui/react-dialog';
import { useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { mutate } from '@/features/Vision/Profile/mutate';
import { useDirtyGuard } from '@/features/Vision/Profile/useDirtyGuard';
import CabinetLayout from '@/layouts/CabinetLayout';
import { Button } from '@/components/ui/forms';
import { QuestionnaireFields } from '@/features/Vision/Profile/QuestionnaireFields';
import {
  answerText,
  labels,
  readAnswers,
  writeStep,
  type Answers,
} from '@/features/Vision/Profile/answers';
type Profile = { id: string; quiz_id: string; version: number; checksum: string; answers: Answers };
type Props = {
  profile: Profile;
  previous_profile: Profile | null;
  history: { id: string; version: number; source: string; created_at: string }[];
  is_latest: boolean;
  limits: { competitors?: number; facts?: number; questions?: number };
  errors?: Record<string, string>;
};
function dateUtc(value: string): string {
  const parsed = new Date(value.replace(' ', 'T').replace(/([+-]\d{2})$/, '$1:00'));
  return Number.isNaN(parsed.getTime())
    ? 'Дата недоступна'
    : new Intl.DateTimeFormat('ru-RU', {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'UTC',
      }).format(parsed) + ' UTC';
}
function Details({ profile, previous_profile, history, is_latest, limits, errors = {} }: Props) {
  const { brand } = usePage<{ brand: { id: string } | null }>().props;
  const [section, setSection] = useState<number | null>(null);
  const [data, setData] = useState(() => readAnswers(profile.answers, { name: '' }));
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const dirty =
    section !== null &&
    JSON.stringify(data) !== JSON.stringify(readAnswers(profile.answers, { name: '' }));
  const leaveAllowedRef = useDirtyGuard(dirty);
  const save = async () => {
    if (!section) return;
    setBusy(true);
    setError('');
    try {
      const redirect = await mutate(`/vision/profile/step/${section}`, 'PATCH', brand?.id ?? '', {
        profile_id: profile.id,
        values: { ...(profile.answers[`step${section}`] ?? {}), ...writeStep(section, data) },
      });
      leaveAllowedRef.current = true;
      window.location.assign(redirect);
    } catch (failure) {
      setError(failure instanceof Error ? failure.message : 'Не удалось сохранить изменения.');
      setBusy(false);
    }
  };
  return (
    <CabinetLayout
      breadcrumbs={[{ label: 'Видение', href: '/vision' }, { label: 'Профиль бренда' }]}
    >
      <div className="space-y-5">
        <header>
          <h1 className="text-3xl font-bold">Профиль бренда</h1>
          <p className="mt-2 text-base text-(--color-ink-muted)">
            Ответы анкеты и сведения для следующих замеров · версия {profile.version}
          </p>
          <Link href="/vision/questions" className="mt-3 inline-block text-sm text-(--color-link) underline underline-offset-2">
            Открыть вопросы для пересборки
          </Link>
        </header>
        {error && (
          <p role="alert" className="rounded-lg bg-red-50 p-4 text-sm text-red-800">
            {error}
          </p>
        )}
        <div className="rounded-lg bg-(--color-brand-primary-soft) p-4 text-sm">
          Изменения профиля применятся к следующему замеру. Данные предоставлены вами и не являются независимо подтверждёнными фактами.
        </div>
        <div className="grid gap-4 lg:grid-cols-2">
          {labels.slice(0, 7).map((title, i) => (
            <section key={title} className="rounded-xl bg-(--color-surface) p-5">
              <div className="flex items-center justify-between gap-3">
                <h2 className="font-semibold">{title}</h2>
                {is_latest && (
                  <button
                    type="button"
                    className="cursor-pointer text-sm text-(--color-link)"
                    onClick={() => {
                      if (
                        dirty &&
                        !window.confirm(
                          'У вас есть несохранённые изменения. Открыть другой раздел?',
                        )
                      )
                        return;
                      setData(readAnswers(profile.answers, { name: '' }));
                      setSection(i + 1);
                      setError('');
                    }}
                  >
                    Изменить
                  </button>
                )}
              </div>
              {section === i + 1 ? (
                <div className="mt-4 space-y-4">
                  <QuestionnaireFields
                    step={section}
                    data={data}
                    onChange={setData}
                    limits={limits}
                    errors={errors}
                  />
                  <div className="flex gap-2">
                    <Button loading={busy} onClick={save}>
                      Сохранить
                    </Button>
                    <Button variant="secondary" onClick={() => setSection(null)}>
                      Отмена
                    </Button>
                  </div>
                </div>
              ) : (
                <p className="mt-3 whitespace-pre-wrap break-words text-sm text-(--color-ink-muted)">
                  {answerText(profile.answers[`step${i + 1}`])}
                </p>
              )}
            </section>
          ))}
        </div>
        <section className="rounded-xl bg-(--color-surface) p-5">
          <h2 className="text-xl font-semibold">История изменений</h2>
          <ul className="mt-3 space-y-2">
            {history.map((item) => (
              <li key={item.id}>
                <Link
                  className="text-sm text-(--color-link) underline underline-offset-2"
                  href={`/vision/profile/${item.id}`}
                >
                  Версия {item.version}
                </Link>{' '}
                <span className="text-sm text-(--color-ink-muted)">{dateUtc(item.created_at)}</span>
              </li>
            ))}
          </ul>
          {previous_profile && (
            <Dialog.Root><Dialog.Trigger asChild><button type="button" className="mt-4 min-h-11 cursor-pointer rounded-lg border border-(--color-border) px-4 text-sm font-semibold text-(--color-link)">Сравнить с предыдущей версией</button></Dialog.Trigger><Dialog.Portal><Dialog.Overlay className="fixed inset-0 z-40 bg-black/50"/><Dialog.Content className="fixed left-1/2 top-1/2 z-50 max-h-[85vh] w-[min(92vw,44rem)] -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-xl bg-(--color-surface) p-6 text-(--color-ink)"><div className="flex items-start justify-between gap-4"><Dialog.Title className="text-xl font-bold">История изменений</Dialog.Title><Dialog.Close className="min-h-11 min-w-11 cursor-pointer rounded-lg border border-(--color-border)" aria-label="Закрыть">✕</Dialog.Close></div><Dialog.Description className="mt-2 text-sm text-(--color-ink-muted)">Сравнение текущей и предыдущей версий профиля.</Dialog.Description>
            <div className="mt-5 space-y-4">
              <h3 className="font-semibold">Сравнение с версией {previous_profile.version}</h3>
              {labels.slice(0, 7).map((title, i) => {
                const key = `step${i + 1}`;
                const before = JSON.stringify(previous_profile.answers[key] ?? {}, null, 2);
                const after = JSON.stringify(profile.answers[key] ?? {}, null, 2);
                return before === after ? null : (
                  <section key={key} className="rounded-lg border border-(--color-border) p-4">
                    <h4 className="font-semibold">{title}</h4>
                    <div className="mt-3 grid gap-4 md:grid-cols-2">
                      <div>
                        <p className="text-sm font-semibold">Было</p>
                        <p className="whitespace-pre-wrap break-words text-sm">
                          {answerText(previous_profile.answers[key])}
                        </p>
                      </div>
                      <div>
                        <p className="text-sm font-semibold">Стало</p>
                        <p className="whitespace-pre-wrap break-words text-sm">
                          {answerText(profile.answers[key])}
                        </p>
                      </div>
                    </div>
                  </section>
                );
              })}
            </div></Dialog.Content></Dialog.Portal></Dialog.Root>
          )}
        </section>
      </div>
    </CabinetLayout>
  );
}
export default function Show(props: Props) {
  return <Details key={props.profile.id} {...props} />;
}
