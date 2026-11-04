import { useState } from 'react';
import { useDirtyGuard } from '@/features/Vision/Profile/useDirtyGuard';
import { Link, router, usePage } from '@inertiajs/react';
import { mutate } from '@/features/Vision/Profile/mutate';
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
import type { Questionnaire } from '@/types/Vision/Profile';
type QuizData = {
  id: string;
  version: number;
  revision: number;
  status: string;
  current_step: number;
  answers: Answers;
};
type Props = {
  quiz: QuizData;
  step: number;
  brand: { id: string; name: string; website: string | null };
  limits: { competitors?: number; facts?: number; questions?: number };
  hints: { status: string; items?: { label: string; value: string; source: string; kind?: string }[] };
  savedAt?: string | null;
  errors?: Record<string, string>;
};
function QuizBody({ quiz, step, brand, limits, hints, savedAt = null, errors = {} }: Props) {
  const savedDate = savedAt ? new Date(savedAt.replace(' ', 'T').replace(/([+-]\d{2})$/, '$1:00')) : null;
  const [data, setData] = useState<Questionnaire>(() => readAnswers(quiz.answers, brand));
  const [busy, setBusy] = useState(false);
  const [checkingSite, setCheckingSite] = useState(false);
  const { can } = usePage<{can?: Record<string, boolean>}>().props;
  const [error, setError] = useState('');
  const [initial] = useState(() => JSON.stringify(readAnswers(quiz.answers, brand)));
  const dirty = JSON.stringify(data) !== initial;
  const leaveAllowedRef = useDirtyGuard(dirty);
  const save = async () => {
    setError('');
    setBusy(true);
    try {
      const redirect = await mutate(`/vision/quiz/step/${step}`, 'PATCH', brand.id, {
        quiz_id: quiz.id,
        revision: quiz.revision,
        values: { ...(quiz.answers[`step${step}`] ?? {}), ...writeStep(step, data) },
      });
      leaveAllowedRef.current = true;
      window.location.assign(redirect);
    } catch (failure) {
      setError(failure instanceof Error ? failure.message : 'Не удалось сохранить шаг.');
      setBusy(false);
    }
  };
  const submit = async () => {
    setError('');
    setBusy(true);
    try {
      const redirect = await mutate('/vision/quiz/submit', 'POST', brand.id, {
        quiz_id: quiz.id,
        revision: quiz.revision,
      });
      if (redirect !== '/vision/profile') throw new Error('Сервер вернул неожиданный адрес профиля.');
      leaveAllowedRef.current = true;
      window.location.assign(redirect);
    } catch (failure) {
      setError(failure instanceof Error ? failure.message : 'Не удалось отправить анкету.');
      setBusy(false);
    }
  };
  return (
    <CabinetLayout breadcrumbs={[{ label: 'Видение', href: '/vision' }, { label: 'Анкета' }]}>
      <div className="space-y-5">
        <header>
          <h1 className="text-3xl font-bold">{labels[step - 1]}</h1>
          <p className="mt-2 text-base text-(--color-ink-muted)">
            Шаг {step} из 8 · Расскажите о вашем бренде
          </p>
          {savedDate && !Number.isNaN(savedDate.getTime()) && <p role="status" className="mt-2 text-sm text-(--color-ink-muted)">Черновик сохранён: {new Intl.DateTimeFormat('ru-RU', {dateStyle:'short',timeStyle:'short'}).format(savedDate)}</p>}
        </header>
        {error && (
          <div role="alert" className="space-y-2 rounded-lg bg-red-50 p-4 text-sm text-red-800">
            <p>{error}</p>
            <p>
              Ваш ввод сохранён на этой странице. Если анкету изменили в другой вкладке, скопируйте
              его и обновите страницу.
            </p>
            <button type="button" className="cursor-pointer font-semibold underline" disabled={busy} onClick={step === 8 ? submit : save}>Повторить</button>
          </div>
        )}
        <div className="grid items-start gap-4 lg:grid-cols-[235px_minmax(0,1fr)_260px]">
          <nav aria-label="Шаги анкеты" className="rounded-xl bg-(--color-surface) p-4">
            <ol className="flex flex-wrap gap-2 lg:flex-col">
              {labels.map((title, i) => (
                <li key={title}>
                  <Link
                    href={`/vision/quiz/${i + 1}`}
                    aria-current={i + 1 === step ? 'step' : undefined}
                    className={`flex min-h-11 items-center gap-3 rounded-lg px-3 text-sm ${i + 1 === step ? 'bg-(--color-brand-primary-soft) font-semibold' : 'text-(--color-link)'}`}
                  >
                    <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-(--color-border)">
                      {i + 1}
                    </span>
                    <span className="hidden lg:inline">{title}</span>
                  </Link>
                </li>
              ))}
            </ol>
          </nav>
          <main className="min-w-0 rounded-xl bg-(--color-surface) p-5 md:p-6">
            {step < 8 ? (
              <>{[3,4,5,7].includes(step) && <aside className="mb-5 rounded-lg bg-(--color-surface-muted) p-4 text-sm"><h2 className="font-semibold">{({3:'Подсказки с сайта',4:'Подсказки о конкурентах',5:'Мы нашли на вашем сайте',7:'Подсказки по вашей сфере'} as Record<number,string>)[step]}</h2><p role="status" className="mt-2 text-(--color-ink-muted)">{hints?.status === 'available' ? 'Проверьте найденные сведения перед добавлением в анкету.' : hints?.status === 'empty' ? 'В аудите сайта подходящих сведений пока нет. Заполните поля самостоятельно.' : 'Подсказки временно недоступны. Заполните поля самостоятельно.'}</p>{step === 3 && (hints?.items ?? []).filter(item => item.kind === 'customer_type').map(item => <button key={`${item.source}-${item.value}`} type="button" className="mt-2 mr-2 cursor-pointer rounded-lg border border-(--color-border) px-3 py-2 disabled:opacity-50" disabled={data.customer_types.includes(item.value)} onClick={() => setData({...data,customer_types:[...data.customer_types,item.value]})}>Тип покупателя: {item.value} ({item.source}) +</button>)}<Link href={`/vision/quiz/${step}`} className="mt-2 inline-block cursor-pointer text-(--color-link) underline">Повторить поиск подсказок</Link></aside>}<QuestionnaireFields
                key={step}
                step={step}
                hints={(hints?.items ?? []).filter(item => item.kind !== 'customer_type').map((item) => ({
                  label: `${item.label} (${item.source})`,
                  value: item.value,
                }))}
                data={data}
                onChange={setData}
                limits={limits}
                errors={errors}
              />{step === 1 && <section className="mt-4 text-sm"><p role="status" className="text-(--color-ink-muted)">{hints?.status === 'available' && data.website === brand.website ? 'Сайт доступен на момент сохранения данных; текущую доступность проверьте повторно.' : 'Для указанного сайта нет сохранённых данных проверки.'}</p><p className="mt-2 text-(--color-ink-muted)">Для нового запуска сохраните данные анкеты и укажите адрес в настройках бренда. Результат откроется в разделе «Сайт».</p>{can?.['vision.actions.write'] && <button type="button" disabled={dirty || !brand.website || data.website !== brand.website || checkingSite} className="mt-2 min-h-11 cursor-pointer rounded-lg border border-(--color-border) px-3 text-(--color-link) disabled:cursor-not-allowed disabled:opacity-50" onClick={() => { if (!brand.website) return; setError(''); router.post('/vision/site/audit', {url: brand.website}, {onStart:()=>setCheckingSite(true),onFinish:()=>setCheckingSite(false),onError:(errors)=>setError(errors.url ?? 'Не удалось запустить проверку сайта.'),onNetworkError:()=>{setError('Нет связи с сервером. Повторите попытку.');return false;},onHttpException:()=>{setError('Не удалось запустить проверку сайта.');return false;}}); }}>{checkingSite ? 'Запускаем проверку…' : 'Проверить сайт'}</button>} <Link href="/vision/site" className="ml-2 cursor-pointer text-(--color-link) underline">Раздел «Сайт»</Link></section>}</>
            ) : (
              <div className="space-y-4">
                <h2 className="text-xl font-semibold">Проверьте ответы перед отправкой</h2>
                <section className="rounded-lg bg-(--color-surface-muted) p-4"><h3 className="font-semibold">Что будет дальше</h3><ol className="mt-2 list-inside list-decimal space-y-2 text-sm text-(--color-ink-muted)"><li>Сохраним профиль бренда.</li><li>Вы подготовите и утвердите вопросы.</li><li>После настройки можно запустить замер.</li></ol></section>
                {labels.slice(0, 7).map((title, i) => (
                  <section key={title} className="rounded-lg border border-(--color-border) p-4">
                    <div className="flex items-center justify-between gap-2">
                      <h3 className="font-semibold">{title}</h3>
                      <Link href={`/vision/quiz/${i + 1}`} className="text-sm text-(--color-link)">
                        Изменить
                      </Link>
                    </div>
                    <p className="mt-2 whitespace-pre-wrap break-words text-sm text-(--color-ink-muted)">
                      {Object.keys(quiz.answers[`step${i + 1}`] ?? {}).length
                        ? answerText(quiz.answers[`step${i + 1}`])
                        : 'Данные не заполнены'}
                    </p>
                  </section>
                ))}
                <p className="text-sm text-(--color-ink-muted)">
                  Для отправки укажите публичный сайт, минимум два варианта названия включая домен,
                  категорию и её формы, тип предложения, аудиторию и фразу покупателя без названия
                  бренда. После отправки создаётся версия профиля. Создание вопросов и запуск замера
                  выполняются отдельно.
                </p>
              </div>
            )}
          </main>
          <aside className="rounded-xl bg-(--color-surface) p-5">
            <h2 className="font-semibold">Зачем мы это спрашиваем</h2>
            <p className="mt-3 text-sm text-(--color-ink-muted)">
              Ответы описывают ваш бренд и помогают подготовить данные для последующих замеров.
            </p>
            <p className="mt-4 text-sm text-(--color-ink-muted)">
              {hints?.status === 'available'
                ? 'Доступные подсказки можно добавить вручную.'
                : 'Подсказки с сайта сейчас недоступны; заполните поля самостоятельно.'}
            </p>
          </aside>
        </div>
        <footer className="flex flex-wrap justify-between gap-3 rounded-xl bg-(--color-surface) p-4">
          <Link
            href={step === 1 ? '/vision' : `/vision/quiz/${step - 1}`}
            className="inline-flex min-h-10 items-center px-3 text-sm text-(--color-link)"
          >
            ← Назад
          </Link>
          {step < 8 ? (
            <Button loading={busy} onClick={save}>
              Сохранить и продолжить →
            </Button>
          ) : (
            <Button loading={busy} onClick={submit}>
              Отправить анкету →
            </Button>
          )}
        </footer>
      </div>
    </CabinetLayout>
  );
}
export default function Quiz(props: Props) {
  return <QuizBody key={`${props.quiz.id}-${props.step}`} {...props} />;
}
