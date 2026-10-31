import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import CabinetLayout from '@/layouts/CabinetLayout';
import { Button } from '@/components/ui/forms';
import { ArrowRight, ClipboardList, ChartNoAxesCombined, FileCheck2 } from 'lucide-react';
type Current = { quiz_id: string; revision: number; current_step: number; status: string } | null;
export default function Start({
  current,
  profile_id,
  can_edit,
  secure_context,
}: {
  current: Current;
  profile_id: string | null;
  can_edit: boolean;
  secure_context: boolean;
}) {
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState('');
  const secure = secure_context && typeof window !== 'undefined' && !!window.crypto?.subtle;
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
          {message && (
            <p role="alert" className="mt-4 text-sm text-red-800">
              {message}
            </p>
          )}
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
        </section>
        <section className="rounded-2xl bg-(--color-surface) p-6">
          <h2 className="text-xl font-semibold">Что проверяем</h2>
          <p className="mt-2 text-sm text-(--color-ink-muted)">
            Упоминания бренда, товаров и преимуществ в ответах моделей. Тарифные значения
            показываются после настройки.
          </p>
        </section>
      </div>
    </CabinetLayout>
  );
}
