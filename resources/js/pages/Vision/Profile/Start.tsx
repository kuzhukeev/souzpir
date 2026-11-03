import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import CabinetLayout from '@/layouts/CabinetLayout';
import { Button } from '@/components/ui/forms';
import { ArrowRight, ClipboardList, ChartNoAxesCombined, FileCheck2 } from 'lucide-react';
import { labels } from '@/features/Vision/Profile/answers';
type Current = { quiz_id: string; revision: number; current_step: number; status: string } | null;
export default function Start({
  current,
  profile_id,
  can_edit,
  secure_context,
  local_http,
}: {
  current: Current;
  profile_id: string | null;
  can_edit: boolean;
  secure_context: boolean;
  local_http: boolean;
}) {
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState('');
  const secure =
    secure_context && typeof window !== 'undefined' && (local_http || !!window.crypto?.subtle);
  return (
    <CabinetLayout breadcrumbs={[{ label: 'Видение' }]}>
      <div className="space-y-6">
        <section className="rounded-2xl bg-(--color-surface) p-6 md:p-8">
          <span className="text-sm text-(--color-link)">Модуль «Видение»</span>
          <h1 className="mt-3 max-w-3xl text-3xl font-bold">
            Узнайте, рекомендуют ли вас нейросети
          </h1>
          <p className="mt-3 text-base text-(--color-ink-muted)">
            Расскажите о бренде, чтобы подготовить профиль для следующих замеров.
          </p>
          {!secure && (
            <p role="status" className="mt-4 text-sm">
              Анкета доступна по HTTPS или на localhost с поддержкой Web Crypto.
            </p>
          )}
          {local_http && (
            <p role="status" className="mt-4 text-sm text-(--color-ink-muted)">
              Локальный режим: история анкеты в этом браузере не шифруется.
            </p>
          )}
          {message && (
            <p role="alert" className="mt-4 text-sm text-red-800">
              {message}
            </p>
          )}
          {current?.status === 'draft' && <div className="mt-4 max-w-md text-sm"><p>Шаг {current.current_step} из 8 · {labels[current.current_step - 1] ?? 'Анкета'} · {Math.round(current.current_step / 8 * 100)} %</p><progress className="mt-2 h-2 w-full" max={8} value={current.current_step} aria-label="Прогресс анкеты" /></div>}
          <div className="mt-5 flex flex-wrap gap-3">
            {can_edit && !profile_id && (
              <Button
                disabled={!secure || busy}
                loading={busy}
                onClick={() => {
                  setMessage('');
                  setBusy(true);
                  router.post(
                    '/vision/quiz/start',
                    {},
                    {
                      onError: () => setMessage('Не удалось начать анкету. Попробуйте ещё раз.'),
                      onFinish: () => setBusy(false),
                    },
                  );
                }}
              >
                {current ? 'Продолжить анкету' : 'Начать анкету'} <ArrowRight size={18} />
              </Button>
            )}
            {profile_id && secure && (
              <Link
                href="/vision/profile"
                className="inline-flex min-h-10 items-center rounded-lg border border-(--color-border) px-4 text-sm font-semibold text-(--color-link)"
              >
                Профиль бренда
              </Link>
            )}
          </div>
          <div className="mt-8 grid gap-5 sm:grid-cols-3">
            {[
              [ClipboardList, 'Анкета', 'Расскажите о компании и целях'],
              [ChartNoAxesCombined, 'Замер', 'Проверка видимости бренда'],
              [FileCheck2, 'Отчёт', 'Результаты и рекомендации'],
            ].map(([Icon, title, text]) => {
              const I = Icon as typeof ClipboardList;
              return (
                <div
                  key={String(title)}
                  className="space-y-2 rounded-xl bg-(--color-surface-muted) p-4"
                >
                  <I aria-hidden="true" className="text-(--color-link)" />
                  <h2 className="font-semibold">{String(title)}</h2>
                  <p className="text-sm text-(--color-ink-muted)">{String(text)}</p>
                </div>
              );
            })}
          </div>
          <section className="mt-8 rounded-xl border border-(--color-border) p-4"><h2 className="font-semibold">Что проверяем</h2><p className="mt-2 text-sm text-(--color-ink-muted)">Пример набора моделей; доступные модели определяются настройками замера.</p><ul className="mt-3 flex flex-wrap gap-2 text-sm">{[['ChatGPT','✳'], ['Claude','✺'], ['Gemini','✦'], ['Perplexity','◈']].map(([name, mark]) => <li key={name} className="flex items-center gap-2 rounded-lg bg-(--color-surface-muted) px-3 py-2"><span aria-hidden="true" className="flex h-7 w-7 items-center justify-center rounded-full bg-(--color-brand-primary-soft) text-lg text-(--color-link)">{mark}</span>{name}</li>)}</ul></section>
          <section className="mt-4 rounded-xl border border-(--color-border) p-4"><h2 className="font-semibold">Ваш тариф</h2><p className="mt-2 text-sm text-(--color-ink-muted)">Данные о тарифе и лимитах пока недоступны.</p></section>
        </section>
      </div>
    </CabinetLayout>
  );
}
