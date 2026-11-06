import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import CabinetLayout from '@/layouts/CabinetLayout';
import { Button } from '@/components/ui/forms';
import { ArrowRight, ClipboardList, ChartNoAxesCombined, FileCheck2 } from 'lucide-react';
import { labels } from '@/features/Vision/Profile/answers';
const displayLimit = (value: string | null) => value === null ? 'Нет данных' : value.replace(/\.0+$/, '');
type Current = { quiz_id: string; revision: number; current_step: number; status: string } | null;
export default function Start({
  current,
  profile_id,
  can_edit,
  secure_context,
  local_http,
  plan = null,
}: {
  current: Current;
  profile_id: string | null;
  can_edit: boolean;
  secure_context: boolean;
  local_http: boolean;
  plan?: {name: string | null; questions: string | null; runs_per_week: string | null} | null;
}) {
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState('');
  const [failedLogo, setFailedLogo] = useState<string | null>(null);
  const { brandAppearance, brand, can } = usePage<{brandAppearance?: {logo_url?: string | null}; brand?: {id: string; name: string} | null; can?: Record<string, boolean>}>().props;
  const logo = brandAppearance?.logo_url;
  const secure =
    secure_context && typeof window !== 'undefined' && (local_http || !!window.crypto?.subtle);
  return (
    <CabinetLayout breadcrumbs={[{ label: 'Видение' }]}>
      <div className="space-y-6">
        <section className="rounded-2xl bg-(--color-surface) p-6 md:p-8">
          {logo && logo !== failedLogo ? <img src={logo} alt={`Логотип бренда ${brand?.name ?? ''}`} onError={() => setFailedLogo(logo)} className="mb-4 h-14 max-w-40 object-contain object-left" /> : can?.['brand.write'] && brand?.id ? <p className="mb-4 text-sm text-(--color-ink-muted)">{logo ? "Логотип недоступен." : "Логотип не загружен."} <Link href={`/brands/${brand.id}/settings?tab=theme`} className="cursor-pointer text-(--color-link) underline">Добавить в настройках бренда</Link></p> : null}
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
              [ClipboardList, 'Анкета · около 10 минут', 'Расскажите о компании и целях'],
              [ChartNoAxesCombined, 'Замер', 'Проверка видимости бренда; сроки зависят от настроек'],
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
          <section className="mt-8 rounded-xl border border-(--color-border) p-4"><h2 className="font-semibold">Пример отчёта</h2><p className="mt-2 text-sm text-(--color-ink-muted)">Иллюстрация структуры будущего отчёта, без показателей вашего бренда.</p><div aria-hidden="true" className="mt-4 space-y-3 rounded-lg bg-(--color-surface-muted) p-5 blur-[3px]"><div className="h-4 w-1/3 rounded bg-(--color-border)"/><div className="h-14 rounded bg-(--color-border)"/><div className="h-4 w-2/3 rounded bg-(--color-border)"/><div className="h-4 w-1/2 rounded bg-(--color-border)"/></div></section>
          <section className="mt-8 rounded-xl border border-(--color-border) p-4"><h2 className="font-semibold">Что проверяем</h2><p className="mt-2 text-sm text-(--color-ink-muted)">Пример набора моделей; доступные модели определяются настройками замера.</p><ul className="mt-3 flex flex-wrap gap-2 text-sm">{[['ChatGPT','✳'], ['Claude','✺'], ['Gemini','✦'], ['Perplexity','◈']].map(([name, mark]) => <li key={name} className="flex items-center gap-2 rounded-lg bg-(--color-surface-muted) px-3 py-2"><span aria-hidden="true" className="flex h-7 w-7 items-center justify-center rounded-full bg-(--color-brand-primary-soft) text-lg text-(--color-link)">{mark}</span>{name}</li>)}</ul></section>
          <section className="mt-4 rounded-xl border border-(--color-border) p-4"><h2 className="font-semibold">Ваш тариф</h2>{plan && (plan.name || plan.questions !== null || plan.runs_per_week !== null) ? <dl className="mt-2 space-y-1 text-sm text-(--color-ink-muted)"><div><dt className="inline">План: </dt><dd className="inline">{plan.name ?? 'Нет данных'}</dd></div><div><dt className="inline">Вопросы: </dt><dd className="inline">{displayLimit(plan.questions)}</dd></div><div><dt className="inline">Прогоны в неделю: </dt><dd className="inline">{displayLimit(plan.runs_per_week)}</dd></div></dl> : <p className="mt-2 text-sm text-(--color-ink-muted)">Данные о тарифе и лимитах пока недоступны.</p>}</section>
        </section>
      </div>
    </CabinetLayout>
  );
}
